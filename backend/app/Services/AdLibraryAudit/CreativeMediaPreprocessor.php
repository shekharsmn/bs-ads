<?php

namespace App\Services\AdLibraryAudit;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class CreativeMediaPreprocessor
{
    public function __construct(private readonly SafePublicUrl $safeUrl) {}

    public function collect(array $snapshot, string $depth): array
    {
        if ($depth === 'text_only') return [];
        $videoUrl = (string) (($snapshot['video_urls'] ?? [])[0] ?? '');
        if ($videoUrl !== '') {
            $video = $this->download($videoUrl, (int) config('ad_library_audit.media.max_video_bytes'), false);
            if (! $video) return [];
            if ($depth === 'full_media') return [$video];

            return $this->extractFrames($video);
        }

        $media = [];
        foreach (array_slice($snapshot['image_urls'] ?? [], 0, 3) as $url) {
            if ($item = $this->download((string) $url, (int) config('ad_library_audit.media.max_image_bytes'), true)) $media[] = $item;
        }

        return $media;
    }

    protected function extractFrames(array $video): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adaudit-video-');
        $frameDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adaudit-frames-'.bin2hex(random_bytes(6));
        @mkdir($frameDir, 0700, true);
        try {
            file_put_contents($tmp, $video['data']);
            $pattern = $frameDir.DIRECTORY_SEPARATOR.'frame-%02d.jpg';
            $process = new Process(['ffmpeg', '-loglevel', 'error', '-i', $tmp, '-vf', 'fps=1/5,scale=1280:-2', '-frames:v', (string) config('ad_library_audit.media.frames', 6), $pattern]);
            $process->setTimeout(90);
            $process->run();
            if (! $process->isSuccessful()) return [];
            $frames = [];
            foreach (glob($frameDir.DIRECTORY_SEPARATOR.'*.jpg') ?: [] as $path) $frames[] = ['mime' => 'image/jpeg', 'data' => file_get_contents($path) ?: ''];
            return $frames;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
            foreach (glob($frameDir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) @unlink($path);
            @rmdir($frameDir);
        }
    }

    private function download(string $url, int $maxBytes, bool $imageOnly): ?array
    {
        try {
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $this->safeUrl->assert($url);
                $response = Http::withOptions(['allow_redirects' => false, 'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                    if (($downloadTotal > 0 && $downloadTotal > $maxBytes) || $downloaded > $maxBytes) throw new \RuntimeException('Media exceeded its size limit.');
                }])->timeout(30)->get($url);
                if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) break;
                $location = $response->header('Location');
                if (! $location || ! preg_match('#^https?://#i', $location)) return null;
                $url = $location;
            }
            if (! $response->successful() || strlen($response->body()) > $maxBytes) return null;
            $mime = strtolower(explode(';', $response->header('Content-Type', 'application/octet-stream'))[0]);
            if ($imageOnly && ! str_starts_with($mime, 'image/')) return null;
            if (! $imageOnly && ! str_starts_with($mime, 'video/')) return null;
            return ['mime' => $mime, 'data' => $response->body()];
        } catch (\Throwable) {
            return null;
        }
    }
}
