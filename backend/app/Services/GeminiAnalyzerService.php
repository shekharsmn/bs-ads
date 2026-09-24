<?php

namespace App\Services;

use App\Models\GeminiRequestLog;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

class GeminiAnalyzerService
{
    private Client $http;
    private string $apiKey;
    private string $model = 'gemini-2.5-flash-lite';
    private string $apiBase = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->http = new Client(['timeout' => 120]);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !str_contains($this->apiKey, 'YOUR_');
    }

    private function download(string $url, int $maxBytes = 10_000_000): ?string
    {
        try {
            $resp = $this->http->get($url, ['headers' => ['User-Agent' => 'Mozilla/5.0'], 'stream' => false]);
            $body = (string)$resp->getBody();
            return strlen($body) > $maxBytes ? substr($body, 0, $maxBytes) : $body;
        } catch (\Exception) {
            return null;
        }
    }

    private function detectMime(string $data): string
    {
        if (str_starts_with($data, "\x89PNG")) return 'image/png';
        if (str_starts_with($data, 'GIF8')) return 'image/gif';
        if (str_starts_with($data, 'RIFF')) return 'image/webp';
        if (substr($data, 4, 4) === 'ftyp') return 'video/mp4';
        return 'image/jpeg';
    }

    private function extractAudio(string $videoPath): ?array
    {
        try {
            $audioPath = $videoPath . '.mp3';
            $proc = new Process([
                'ffmpeg', '-i', $videoPath,
                '-vn', '-acodec', 'libmp3lame', '-ar', '16000', '-ac', '1', '-b:a', '64k',
                $audioPath, '-y',
            ]);
            $proc->setTimeout(30);
            $proc->run();
            if ($proc->isSuccessful() && file_exists($audioPath)) {
                $data = file_get_contents($audioPath);
                unlink($audioPath);
                // Cap at 5MB to stay within Gemini inline limit
                return strlen($data) <= 5_000_000
                    ? ['data' => $data, 'mime' => 'audio/mp3']
                    : null;
            }
        } catch (\Exception) {}
        return null;
    }

    private function extractVideoFrames(string $videoData, int $nFrames = 4): array
    {
        $frames = [];
        try {
            $tmpDir = sys_get_temp_dir() . '/gemini_' . uniqid();
            mkdir($tmpDir, 0777, true);
            $videoPath = $tmpDir . '/video.mp4';
            file_put_contents($videoPath, $videoData);

            $probe = new Process(['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', $videoPath]);
            $probe->setTimeout(10);
            $probe->run();
            $duration = 30.0;
            if ($probe->isSuccessful()) {
                $info = json_decode($probe->getOutput(), true);
                $duration = (float)($info['format']['duration'] ?? 30);
            }

            $timestamps = [0.5, $duration * 0.25, $duration * 0.5, $duration * 0.75];
            foreach (array_slice($timestamps, 0, $nFrames) as $ts) {
                $outPath = $tmpDir . "/f_{$ts}.jpg";
                $proc = new Process(['ffmpeg', '-ss', (string)$ts, '-i', $videoPath, '-frames:v', '1', '-q:v', '2', $outPath, '-y']);
                $proc->setTimeout(15);
                $proc->run();
                if ($proc->isSuccessful() && file_exists($outPath)) {
                    $frames[] = [number_format($ts, 1) . 's', file_get_contents($outPath)];
                    unlink($outPath);
                }
            }
            unlink($videoPath);
            rmdir($tmpDir);
        } catch (\Exception) {
        }
        return $frames;
    }

    private int $totalInputTokens  = 0;
    private int $totalOutputTokens = 0;

    // Context set by the job before each ad's analysis
    private ?int   $logUserId        = null;
    private ?int   $logReportId      = null;
    private ?int   $logMetaAccountId = null;
    private string $logAdName        = '';
    private string $logCallType      = 'unknown';

    public function setLogContext(int $userId, int $reportId, int $metaAccountId): void
    {
        $this->logUserId        = $userId;
        $this->logReportId      = $reportId;
        $this->logMetaAccountId = $metaAccountId;
    }

    public function setAdContext(string $adName, string $callType): void
    {
        $this->logAdName   = $adName;
        $this->logCallType = $callType;
    }

    public function getTokenUsage(): array
    {
        return [
            'input_tokens'  => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
        ];
    }

    private function callGemini(array $parts): string
    {
        $contents = [];
        foreach ($parts as $part) {
            if (is_string($part)) {
                $contents[] = ['text' => $part];
            } elseif (isset($part['data'], $part['mime'])) {
                $contents[] = ['inlineData' => ['mimeType' => $part['mime'], 'data' => base64_encode($part['data'])]];
            }
        }

        $resp = $this->http->post(
            "{$this->apiBase}/models/{$this->model}:generateContent?key={$this->apiKey}",
            ['json' => ['contents' => [['parts' => $contents]]]]
        );

        $body = json_decode($resp->getBody(), true);

        // Accumulate token usage from response metadata
        $usage  = $body['usageMetadata'] ?? [];
        $inTok  = (int)($usage['promptTokenCount']     ?? 0);
        $outTok = (int)($usage['candidatesTokenCount'] ?? 0);
        $this->totalInputTokens  += $inTok;
        $this->totalOutputTokens += $outTok;

        // Log each individual call if context is set
        if ($this->logReportId) {
            GeminiRequestLog::create([
                'user_id'         => $this->logUserId,
                'report_id'       => $this->logReportId,
                'meta_account_id' => $this->logMetaAccountId,
                'ad_name'         => $this->logAdName ?: null,
                'call_type'       => $this->logCallType,
                'input_tokens'    => $inTok,
                'output_tokens'   => $outTok,
            ]);
        }

        return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function staticPrompt(string $adName, array $copy, array $ins): string
    {
        $ctr = number_format((float)($ins['ctr'] ?? 0), 2);
        $spend = number_format((float)($ins['spend'] ?? 0), 0);
        $freq = number_format((float)($ins['frequency'] ?? 0), 1);
        return "You are a B2B paid advertising creative expert. Analyze this Meta ad image.\n\nAd: {$adName}\nHeadline: " . ($copy['headline'] ?? '(none)') . "\nPrimary text: " . substr($copy['primary_text'] ?? '(none)', 0, 300) . "\nCTA: " . ($copy['cta'] ?? '(none)') . "\nCTR: {$ctr}% | Spend: \${$spend} | Frequency: {$freq}\n\n## 1. VISUAL ASSESSMENT\nDescribe layout, colors, text overlays, people/product shown.\n\n## 2. B2B EFFECTIVENESS SCORE\nScore out of 100 with justification.\n\n## 3. WHAT'S WORKING\n2-3 specific positives.\n\n## 4. CRITICAL ISSUES\nSpecific visual problems you can see.\n\n## 5. ACTIONABLE IMPROVEMENTS\nVery specific — reference what you actually see.\n\n## 6. ALTERNATIVE CREATIVE CONCEPTS\n3 different visual concepts with specific descriptions.";
    }

    private function videoPrompt(string $adName, array $copy, array $ins, float $hookRate, float $thruplayRate, float $avgWatch, float $duration, int $nFrames, bool $hasAudio, ?string $transcript): string
    {
        $hookLabel = $hookRate >= 25 ? 'strong' : ($hookRate < 15 ? 'weak' : 'average');
        $ctr = number_format((float)($ins['ctr'] ?? 0), 2);
        $spend = number_format((float)($ins['spend'] ?? 0), 0);
        $framesDesc = $nFrames > 0 ? "thumbnail + {$nFrames} extracted frames" : "thumbnail only (ffmpeg not available)";
        $audioNote = $hasAudio ? " + full audio track" : "";
        $transcriptSection = $transcript
            ? "\n\nVIDEO TRANSCRIPT (auto-generated):\n\"\"\"\n{$transcript}\n\"\"\""
            : "\n\n(No audio transcript available.)";

        return "You are a B2B video ad expert. Analyze this video ad.\n\nAd: {$adName}\nHeadline: " . ($copy['headline'] ?? '(none)') . "\nPrimary text: " . substr($copy['primary_text'] ?? '(none)', 0, 300) . "\nCTA: " . ($copy['cta'] ?? '(none)') . "\n\nPerformance:\n- Duration: {$duration}s | CTR: {$ctr}% | Spend: \${$spend}\n- Hook Rate: {$hookRate}% — benchmark 25%+ [{$hookLabel}]\n- ThruPlay Rate: {$thruplayRate}% — benchmark 15%+\n- Avg Watch Time: {$avgWatch}s{$transcriptSection}\n\nI'm showing you the {$framesDesc}{$audioNote}.\n\n## 1. VISUAL ASSESSMENT\nDescribe each frame: setting, people, on-screen text, production quality.\n\n## 2. HOOK ANALYSIS (0-3 seconds)\nHook rate is {$hookRate}% ({$hookLabel}). Based on the opening frame and script, explain why and what exact change would improve it.\n\n## 3. RETENTION ANALYSIS\nThruPlay rate {$thruplayRate}% and avg watch {$avgWatch}s out of {$duration}s. Where does momentum likely break?\n\n## 4. SCRIPT & MESSAGING ANALYSIS\n" . ($transcript ? "Analyze the actual transcript above:" : "Assess from visuals and copy:") . " B2B relevance, problem/solution structure, hook strength, CTA clarity. Flag weak lines and suggest rewrites.\n\n## 5. B2B EFFECTIVENESS SCORE\nScore out of 100 with justification.\n\n## 6. WHAT'S WORKING\n2-3 specific positives.\n\n## 7. ACTIONABLE IMPROVEMENTS\nSpecific changes — first 3 seconds, script rewrites, visual structure, trust signals.\n\n## 8. ALTERNATIVE VIDEO CONCEPTS\n3 formats that would outperform this for B2B on Meta.";
    }

    public function analyzeStatic(string $imageUrl, array $copy, array $ins, string $adName): array
    {
        $data = $this->download($imageUrl);
        if (!$data) return ['error' => 'Could not download image'];
        try {
            $this->setAdContext($adName, 'static_analysis');
            $text = $this->callGemini([
                ['data' => $data, 'mime' => $this->detectMime($data)],
                $this->staticPrompt($adName, $copy, $ins),
            ]);
            return ['type' => 'static', 'analysis_text' => $text];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function analyzeVideo(array $videoInfo, array $copy, array $ins, string $adName, float $hookRate, float $thruplayRate, float $avgWatch): array
    {
        $thumbUrl = $videoInfo['picture'] ?? null;
        $videoUrl = $videoInfo['source'] ?? null;
        $duration = (float)($videoInfo['length'] ?? 0);
        $frames = [];
        $audio = null;
        $transcript = null;

        if ($videoUrl) {
            $videoData = $this->download($videoUrl, 50_000_000);
            if ($videoData) {
                // Write to temp file so both frame extractor and audio extractor can share it
                $tmpVideo = sys_get_temp_dir() . '/gemini_vid_' . uniqid() . '.mp4';
                file_put_contents($tmpVideo, $videoData);

                $frames = $this->extractVideoFrames($videoData);
                $audio  = $this->extractAudio($tmpVideo);

                @unlink($tmpVideo);
            }
        }

        // Step 1: get transcript from audio via Gemini (separate focused call)
        if ($audio) {
            try {
                $this->setAdContext($adName, 'transcript');
                $transcript = trim($this->callGemini([
                    ['data' => $audio['data'], 'mime' => $audio['mime']],
                    "Transcribe every word spoken in this video ad audio, exactly as said. Include on-screen text read aloud. Output only the raw transcript, no labels or explanations. If there is no speech, output: [no speech]",
                ]));
                if ($transcript === '[no speech]' || strlen($transcript) < 5) $transcript = null;
            } catch (\Exception) {}
        }

        // Step 2: full analysis with frames + audio + transcript
        $parts = [];
        if ($thumbUrl && $thumbData = $this->download($thumbUrl)) {
            $parts[] = ['data' => $thumbData, 'mime' => $this->detectMime($thumbData)];
            $parts[] = '↑ Video thumbnail / cover frame';
        }
        foreach ($frames as [$ts, $frameData]) {
            $parts[] = ['data' => $frameData, 'mime' => 'image/jpeg'];
            $parts[] = "↑ Frame at {$ts}";
        }
        if ($audio) {
            $parts[] = ['data' => $audio['data'], 'mime' => $audio['mime']];
            $parts[] = '↑ Full audio track';
        }
        if (!$parts) return ['error' => 'No visual data available'];

        $parts[] = $this->videoPrompt(
            $adName, $copy, $ins, $hookRate, $thruplayRate, $avgWatch,
            $duration, count($frames), $audio !== null, $transcript
        );

        try {
            $this->setAdContext($adName, 'video_analysis');
            $text = $this->callGemini($parts);
            return [
                'type'        => 'video',
                'analysis_text' => $text,
                'transcript'  => $transcript,
                'has_frames'  => count($frames) > 0,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'type' => 'video', 'transcript' => $transcript];
        }
    }
}
