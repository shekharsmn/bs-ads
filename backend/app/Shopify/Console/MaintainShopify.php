<?php

namespace App\Shopify\Console;

use App\Shopify\Jobs\ProcessWebhook;
use App\Shopify\Jobs\SyncShop;
use App\Shopify\Models\Installation;
use App\Shopify\Models\SyncRun;
use App\Shopify\Models\WebhookEvent;
use App\Shopify\ShopifyException;
use App\Shopify\SyncCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class MaintainShopify extends Command
{
    protected $signature = 'shopify:maintain {--kind=incremental : incremental, recent, reconcile, or inbox}';

    protected $description = 'Resume Shopify synchronization and maintain the durable webhook inbox';

    public function handle(SyncCoordinator $sync): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('shop_hooks')) {
            return self::SUCCESS;
        }
        $kind = $this->option('kind');
        if (! in_array($kind, ['incremental', 'recent', 'reconcile', 'inbox'], true)) {
            return self::INVALID;
        }
        // Privacy inbox continues to run even while new Shopify installs/syncs are disabled.
        WebhookEvent::where('status', 'pending')->where('updated_at', '<', now()->subMinute())->chunkById(100, fn ($rows) => $rows->each(fn ($e) => ProcessWebhook::dispatch($e->id)));
        WebhookEvent::where('created_at', '<', now()->subDays(7))->whereNotNull('payload')
            ->where(fn ($q) => $q->where('status', 'processed')->orWhereNotIn('topic', ['customers/data_request', 'customers/redact', 'shop/redact']))->update(['payload' => null]);
        WebhookEvent::where('created_at', '<', now()->subDays(30))->whereNotNull('payload')->update(['payload' => null]);
        WebhookEvent::where('created_at', '<', now()->subDays(7))->where('status', 'pending')
            ->whereNotIn('topic', ['customers/data_request', 'customers/redact', 'shop/redact'])->update(['status' => 'failed', 'error_code' => 'retention_expired']);
        foreach (Storage::disk('local')->files('shopify') as $file) {
            $age = now()->timestamp - Storage::disk('local')->lastModified($file);
            if ($age > 86400 && preg_match('~(?:run-\d+\.jsonl(?:\.tmp)?|privacy-\d+\.enc)$~', $file)) {
                Storage::disk('local')->delete($file);
            }
        }
        if (! config('shopify.enabled') || $kind === 'inbox') {
            return self::SUCCESS;
        }
        Installation::where('status', 'active')->chunkById(50, function ($rows) use ($kind, $sync) {
            foreach ($rows as $install) {
                if (! $install->app->active || ! $install->app->data_approved) {
                    continue;
                }
                try {
                    $sync->enqueue($install, $kind);
                } catch (ShopifyException $e) {
                    $install->update(['error_code' => $e->errorCode]);
                }
            }
        });
        // Lost dispatches are safe to repeat: the worker takes the store lock and reloads durable state.
        SyncRun::whereIn('status', ['pending', 'running'])->where('updated_at', '<', now()->subMinutes(3))->chunkById(100, fn ($rows) => $rows->each(fn ($run) => SyncShop::dispatch($run->id)));

        return self::SUCCESS;
    }
}
