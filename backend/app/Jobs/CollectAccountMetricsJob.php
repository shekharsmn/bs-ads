<?php

namespace App\Jobs;

use App\Models\AdAccount;
use App\Services\AlertEngine;
use App\Services\PerformanceCollectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CollectAccountMetricsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $timeout = 300;
    public int $uniqueFor = 3300;
    public function __construct(public readonly int $adAccountId) {}
    public function uniqueId(): string { return 'metrics:'.$this->adAccountId; }

    public function handle(PerformanceCollectionService $collector, AlertEngine $alerts): void
    {
        $account = AdAccount::with(['connection', 'client'])->findOrFail($this->adAccountId);
        try {
            $collector->collect($account);
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);
            $account->connection->update(['status' => 'error', 'last_error' => $e->getMessage(), 'last_synced_at' => now()]);
            Log::error('Performance collection failed', ['ad_account_id' => $account->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $alerts->evaluateAccount($account->fresh(['connection']));
            throw $e;
        }
        $alerts->evaluateAccount($account->fresh(['connection']));
    }
}
