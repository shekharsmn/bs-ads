<?php

namespace App\Jobs;

use App\Models\AdLibraryRun;
use App\Services\AdLibrary\AdLibraryProvider;
use App\Services\AdLibrary\TransientAdLibraryException;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RunAdLibraryScrapeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $backoff = 60;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(config('workloads.queues.ad_library', 'ad-library'));
        $this->timeout = config('workloads.policies.ad_library.timeout', 900);
        $this->tries = config('workloads.policies.ad_library.tries', 2);
        $this->backoff = config('workloads.policies.ad_library.backoff', 60);
    }

    public function handle(AdLibraryProvider $provider, ?WorkflowActivityService $activities = null, ?WorkflowCoordinator $workflows = null): void
    {
        $activities ??= app(WorkflowActivityService::class);
        $workflows ??= app(WorkflowCoordinator::class);
        $run = AdLibraryRun::with('search')->findOrFail($this->runId);
        if (in_array($run->status, ['complete', 'partial', 'empty', 'blocked'], true)) {
            return;
        }

        $started = microtime(true);
        $run->update(['status' => 'running', 'started_at' => now(), 'completed_at' => null, 'error_message' => null]);
        $attempt = $workflows->start($activities->sync($run), 'scraping', 'ad-library-run:'.$run->id.':'.($this->job?->uuid() ?? Str::uuid()));

        try {
            $response = $provider->scrape($run);
            $status = $this->normalizeStatus($response);

            DB::transaction(function () use ($run, $response, $status) {
                $run->results()->delete();
                $seen = [];
                foreach ($response['ads'] as $index => $ad) {
                    if (! is_array($ad) || blank($ad['ad_id'] ?? null)) {
                        continue;
                    }
                    if (isset($seen[(string) $ad['ad_id']])) {
                        continue;
                    }
                    $seen[(string) $ad['ad_id']] = true;
                    $run->results()->create($this->resultPayload($ad, $index + 1));
                }
                $count = $run->results()->count();
                $run->update([
                    'status' => $status,
                    'result_count' => $count,
                    'reported_total' => $response['reported_total'] ?? null,
                    'stop_reason' => $response['stop_reason'] ?? null,
                    'capped' => (bool) ($response['capped'] ?? false),
                    'provider_metadata' => $response['metadata'] ?? null,
                    'error_message' => $response['error'] ?? null,
                    'completed_at' => now(),
                ]);
            });
            $workflows->complete($attempt, $status);

            Log::info('Ad Library scrape finished', ['run_id' => $run->id, 'company_name' => $run->search?->company_name, 'status' => $status, 'result_count' => $run->fresh()->result_count, 'stop_reason' => $response['stop_reason'] ?? null, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        } catch (TransientAdLibraryException $e) {
            Log::warning('Ad Library scrape will retry', ['run_id' => $run->id, 'company_name' => $run->search?->company_name, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $workflows->fail($attempt, $e, 'scrape_retry', projectToActivity: false);
            throw $e;
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $this->safeError($e), 'completed_at' => now()]);
            Log::error('Ad Library scrape failed', ['run_id' => $run->id, 'company_name' => $run->search?->company_name, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $workflows->fail($attempt, $e, 'scrape_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        AdLibraryRun::whereKey($this->runId)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error_message' => $exception ? $this->safeError($exception) : 'The scraper job failed.', 'completed_at' => now()]);
    }

    private function normalizeStatus(array $response): string
    {
        $status = $response['status'] ?? 'failed';

        return in_array($status, ['complete', 'partial', 'empty', 'blocked', 'failed'], true) ? $status : 'failed';
    }

    private function resultPayload(array $ad, int $rank): array
    {
        $json = fn (string $key) => $this->jsonValue($ad[$key] ?? null);

        return [
            'library_ad_id' => (string) $ad['ad_id'], 'rank' => $ad['position'] ?? $rank,
            'advertiser_name' => $ad['advertiser_name'] ?? null, 'advertiser_page_id' => $ad['advertiser_page_id'] ?? null,
            'advertiser_url' => $ad['advertiser_url'] ?? null, 'advertiser_likes' => $ad['advertiser_likes'] ?? null,
            'title' => $ad['title'] ?? null, 'body' => $ad['body'] ?? null, 'product_url' => $ad['product_url'] ?? null,
            'link_url' => $ad['link_url'] ?? null, 'link_title' => $ad['link_title'] ?? null, 'link_description' => $ad['link_description'] ?? null,
            'cta_text' => $ad['cta_text'] ?? null, 'cta_type' => $ad['cta_type'] ?? null, 'is_active' => $ad['is_active'] ?? null,
            'first_shown_at' => $ad['first_shown_at'] ?? null, 'last_shown_at' => $ad['last_shown_at'] ?? null,
            'display_format' => $ad['display_format'] ?? null, 'platforms' => $json('platforms_json'),
            'image_urls' => $json('image_urls_json') ?: array_values(array_filter([$ad['image_url'] ?? null])), 'video_urls' => $json('video_urls_json'),
            'paid_for_by' => $ad['paid_for_by'] ?? null, 'spend' => $ad['spend'] ?? null, 'spend_currency' => $ad['spend_currency'] ?? null,
            'impressions' => $ad['impressions'] ?? null, 'reach' => $ad['reach'] ?? null, 'eu_reach' => $ad['eu_reach'] ?? null, 'uk_reach' => $ad['uk_reach'] ?? null,
            'reach_breakdown' => $json('reach_breakdown_json'), 'target_ages' => $ad['target_ages'] ?? null, 'target_gender' => $ad['target_gender'] ?? null,
            'target_locations' => $json('target_locations_json'), 'payer' => $ad['payer'] ?? null, 'beneficiary' => $ad['beneficiary'] ?? null,
            'advertiser_ig_username' => $ad['advertiser_ig_username'] ?? null, 'advertiser_ig_followers' => $ad['advertiser_ig_followers'] ?? null,
            'advertiser_verification' => $ad['advertiser_verification'] ?? null, 'raw_payload' => $ad,
        ];
    }

    private function jsonValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function safeError(Throwable $e): string
    {
        return mb_substr(preg_replace('/https?:\/\/\S+|Bearer\s+\S+/i', '[redacted]', $e->getMessage()) ?: 'The scraper job failed.', 0, 1000);
    }
}
