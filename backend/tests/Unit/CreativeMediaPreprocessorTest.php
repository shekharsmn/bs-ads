<?php

namespace Tests\Unit;

use App\Services\AdLibraryAudit\CreativeMediaPreprocessor;
use App\Services\AdLibraryAudit\SafePublicUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreativeMediaPreprocessorTest extends TestCase
{
    public function test_visual_video_uses_sampled_video_frames_instead_of_poster_images(): void
    {
        Http::fake([
            'https://media.test/video.mp4' => Http::response('video-bytes', 200, ['Content-Type' => 'video/mp4']),
            'https://media.test/poster.jpg' => Http::response('poster-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $preprocessor = new class($this->permissiveValidator()) extends CreativeMediaPreprocessor {
            protected function extractFrames(array $video): array
            {
                return [['mime' => 'image/jpeg', 'data' => 'sampled-from-'.$video['data']]];
            }
        };

        $media = $preprocessor->collect([
            'video_urls' => ['https://media.test/video.mp4'],
            'image_urls' => ['https://media.test/poster.jpg'],
        ], 'visual');

        $this->assertSame('sampled-from-video-bytes', $media[0]['data']);
        Http::assertSent(fn ($request) => $request->url() === 'https://media.test/video.mp4');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://media.test/poster.jpg');
    }

    public function test_full_media_sends_video_without_duplicate_poster_image(): void
    {
        Http::fake([
            'https://media.test/video.mp4' => Http::response('video-bytes', 200, ['Content-Type' => 'video/mp4']),
            'https://media.test/poster.jpg' => Http::response('poster-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $media = (new CreativeMediaPreprocessor($this->permissiveValidator()))->collect([
            'video_urls' => ['https://media.test/video.mp4'],
            'image_urls' => ['https://media.test/poster.jpg'],
        ], 'full_media');

        $this->assertCount(1, $media);
        $this->assertSame('video/mp4', $media[0]['mime']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://media.test/poster.jpg');
    }

    public function test_static_creative_still_uses_image_urls(): void
    {
        Http::fake(['https://media.test/static.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $media = (new CreativeMediaPreprocessor($this->permissiveValidator()))->collect([
            'video_urls' => [],
            'image_urls' => ['https://media.test/static.jpg'],
        ], 'visual');

        $this->assertCount(1, $media);
        $this->assertSame('image/jpeg', $media[0]['mime']);
    }

    private function permissiveValidator(): SafePublicUrl
    {
        return new class extends SafePublicUrl {
            public function assert(string $url, ?string $requiredHost = null): string { return $url; }
        };
    }
}
