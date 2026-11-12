<?php

namespace App\Shopify;

use App\Shopify\Jobs\SyncShop;
use App\Shopify\Models\Installation;
use App\Shopify\Models\Store;
use App\Shopify\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SyncCoordinator
{
    public function enqueue(Installation $install, string $kind = 'incremental'): SyncRun
    {
        return Cache::lock('shop-run:'.$install->store_id, 10)->block(3, function () use ($install, $kind) {
            $install->refresh();
            if ($install->status !== 'active' || ! $install->app->active) {
                throw new ShopifyException('installation_inactive');
            }
            if (! $install->app->data_approved) {
                throw new ShopifyException('data_approval_required');
            }
            if (! in_array('read_orders', $install->scopes ?? [], true)) {
                throw new ShopifyException('orders_scope_required');
            }
            $active = SyncRun::where('install_id', $install->id)->whereIn('status', ['pending', 'running'])->first();
            if ($active) {
                return $active;
            }
            $store = $install->store;
            if ($store->active_install_id && $store->active_install_id !== $install->id && $store->installation?->installed_at?->gt($install->installed_at)) {
                throw new ShopifyException('installation_superseded');
            }
            $history = $kind === 'history' || ! $store->history_complete || $store->active_install_id !== $install->id;
            $cutoff = now()->subDays(60);
            $from = $history ? $cutoff : ($store->synced_until?->subMinutes(5) ?? $cutoff);
            if ($from->lt($cutoff)) {
                $from = $cutoff;
            }
            if ($kind === 'recent') {
                $from = now()->subDays(30);
            }
            if ($kind === 'reconcile') {
                $from = $cutoff;
            }
            $run = SyncRun::create(['install_id' => $install->id, 'generation' => $install->generation, 'kind' => $history ? 'history' : $kind,
                'from_at' => $from, 'until_at' => now(), 'checkpoint' => ['mode' => 'bulk'], 'status' => 'pending']);
            DB::afterCommit(fn () => SyncShop::dispatch($run->id));

            return $run;
        });
    }

    public function complete(SyncRun $run): void
    {
        DB::transaction(function () use ($run) {
            $install = Installation::whereKey($run->install_id)->lockForUpdate()->firstOrFail();
            if ($install->status !== 'active' || $install->generation !== $run->generation) {
                throw new ShopifyException('installation_changed');
            }
            $store = Store::whereKey($install->store_id)->lockForUpdate()->firstOrFail();
            // Switching app registrations is only allowed after a complete history reacquisition.
            if ($store->active_install_id !== $install->id && $run->kind !== 'history') {
                throw new ShopifyException('replacement_history_required');
            }
            if ($store->active_install_id && $store->active_install_id !== $install->id) {
                Installation::whereKey($store->active_install_id)->update(['status' => 'superseded', 'tokens' => null]);
            }
            if (in_array($run->kind, ['history', 'reconcile'], true)) {
                $missing = Models\Order::where('store_id', $store->id)->where(fn ($q) => $q->where('run_id', '!=', $run->id)->orWhereNull('run_id'))
                    ->where('source_updated_at', '<', $run->until_at);
                $missing->where('placed_at', '>=', $run->from_at ?? $run->until_at->subDays(60));
                $missing->update(['deleted' => true]);
            }
            $store->update(['active_install_id' => $install->id, 'last_synced_at' => now(), 'synced_until' => $run->until_at,
                'history_complete' => true, 'limited_history' => true,
                'history_from' => $run->kind === 'history' ? ($run->from_at ?? Models\Order::where('store_id', $store->id)->min('placed_at') ?? $run->until_at) : $store->history_from]);
            $run->update(['status' => 'completed', 'completed_at' => now(), 'stage' => 'done']);
        });
    }
}
