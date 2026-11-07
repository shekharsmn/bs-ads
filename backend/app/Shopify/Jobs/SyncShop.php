<?php

namespace App\Shopify\Jobs;

use App\Shopify\Models\SyncRun;
use App\Shopify\ShopifyException;
use App\Shopify\SyncEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class SyncShop implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(config('shopify.queue'));
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(SyncEngine $engine): void
    {
        $run = SyncRun::find($this->runId);
        if (! $run || ! in_array($run->status, ['pending', 'running'], true) || ! config('shopify.enabled')) {
            return;
        }
        $lock = Cache::lock('shop-process:'.$run->installation->store_id, 110);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $install = $run->installation;
            if ($install->status !== 'active' || $install->generation !== $run->generation) {
                $run->update(['status' => 'cancelled', 'completed_at' => now()]);

                return;
            }
            $delay = $engine->step($run);
            if ($run->fresh()->status === 'running') {
                $next = self::dispatch($run->id);
                if ($delay > 0) {
                    $next->delay(now()->addSeconds($delay));
                }
            }
        } catch (ShopifyException $e) {
            if ($e->retryable) {
                throw $e;
            }
            $run->update(['status' => 'failed', 'error_code' => $e->errorCode, 'completed_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $error): void
    {
        SyncRun::whereKey($this->runId)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error_code' => $error instanceof ShopifyException ? $error->errorCode : 'worker_failed', 'completed_at' => now()]);
    }
}
