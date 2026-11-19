<?php

namespace App\Jobs;

use App\Models\AdAccount;
use App\Services\AlertEngine;
use App\Services\DataQualityMonitor;
use App\Services\PerformanceCollectionService;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Google\ApiCore\ApiException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CollectAccountMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $backoff = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3300;

    public function __construct(public readonly int $adAccountId, public readonly ?string $startDate = null, public readonly ?string $endDate = null, public readonly ?string $refreshToken = null)
    {
        $this->onQueue(config('workloads.queues.collection', 'collection'));
        $this->timeout = config('workloads.policies.collection.timeout', 300);
        $this->tries = config('workloads.policies.collection.tries', 1);
        $this->backoff = config('workloads.policies.collection.backoff', 60);
    }

    public function uniqueId(): string
    {
        return 'metrics:'.$this->adAccountId.':'.($this->startDate ?? 'incremental').':'.($this->endDate ?? 'current').':'.($this->refreshToken ?? 'scheduled');
    }

    public function handle(PerformanceCollectionService $collector, AlertEngine $alerts, WorkflowActivityService $activities, WorkflowCoordinator $workflows, DataQualityMonitor $quality): void
    {
        $account = AdAccount::with(['connection', 'client'])->findOrFail($this->adAccountId);
        $activity = $activities->beginMetricCollection($account);
        $attempt = $workflows->start($activity, 'collecting', 'metrics:'.$account->id.':'.$this->attempts().':'.($this->job?->uuid() ?? Str::uuid()));
        try {
            $collector->collect($account, $this->startDate, $this->endDate);
        } catch (\Throwable $e) {
            $workflows->fail($attempt, $e, 'collection_failed');
            $account->update(['last_error' => $e->getMessage()]);
            $account->connection->update(['status' => 'error', 'last_error' => $e->getMessage(), 'last_synced_at' => now()]);
            Log::error('Performance collection failed', ['ad_account_id' => $account->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $alerts->evaluateAccount($account->fresh(['connection']));
            if ($this->job instanceof DatabaseJob && ! $this->isTransient($e)) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
        $workflows->heartbeat($attempt, 'evaluating_alerts');
        $alerts->evaluateAccount($account->fresh(['connection']));
        $quality->inspect($account->fresh('client'));
        $workflows->complete($attempt, 'collected');
    }

    private function isTransient(\Throwable $error): bool
    {
        return $error instanceof ConnectionException ||
            ($error instanceof RequestException && ($error->response->status() === 429 || $error->response->serverError())) ||
            ($error instanceof ApiException && in_array($error->getStatus(), ['UNAVAILABLE', 'DEADLINE_EXCEEDED', 'RESOURCE_EXHAUSTED', 'INTERNAL'], true));
    }
}
