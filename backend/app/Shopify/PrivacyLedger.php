<?php

namespace App\Shopify;

use App\Shopify\Models\Installation;
use App\Shopify\Models\Order;
use App\Shopify\Models\PrivacyRequest;
use App\Shopify\Models\Sale;
use App\Shopify\Models\SyncRun;
use App\Shopify\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Retained on the private storage volume, independently of SQL release backups. */
final class PrivacyLedger
{
    public function record(PrivacyRequest $request): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('shopify');
        $entry = $request->only(['app_id', 'domain', 'topic', 'request_key', 'order_gids']);
        $entry['shop_gid'] = Models\Store::where('domain', $request->domain)->value('gid');
        $path = $disk->path('shopify/redactions.enc');
        if (file_put_contents($path, encrypt(json_encode($entry, JSON_THROW_ON_ERROR))."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new ShopifyException('privacy_storage_failed', true);
        }
        @chmod($path, 0600);
    }

    public function replay(): int
    {
        $path = Storage::disk('local')->path('shopify/redactions.enc');
        if (! is_file($path)) {
            return 0;
        }
        $handle = fopen($path, 'rb');
        $count = 0;
        try {
            while (($line = fgets($handle, 1048577)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $entry = json_decode(decrypt(trim($line)), true, 512, JSON_THROW_ON_ERROR);
                if (! empty($entry['shop_gid'])) {
                    $entry['domain'] = Models\Store::where('gid', $entry['shop_gid'])->value('domain') ?? $entry['domain'];
                }
                unset($entry['shop_gid']);
                $request = PrivacyRequest::firstOrCreate(['app_id' => $entry['app_id'], 'request_key' => $entry['request_key']], $entry);
                if ($request->status !== 'completed') {
                    $this->erase($request);
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    public function erase(PrivacyRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $install = Installation::where('app_id', $request->app_id)->whereHas('store', fn ($q) => $q->where('domain', $request->domain))->lockForUpdate()->first();
            $shopRedaction = $request->topic === 'shop/redact';
            $exports = PrivacyRequest::where('domain', $request->domain)->where('topic', 'customers/data_request');
            if ($shopRedaction) {
                $exports->where('app_id', $request->app_id);
            }
            foreach ($exports->lazyById() as $export) {
                if ($shopRedaction || array_intersect($request->order_gids ?? [], $export->order_gids ?? [])) {
                    $this->deleteFiles('shopify/privacy-'.$export->id.'.enc');
                }
            }
            if ($install) {
                if ($shopRedaction) {
                    Sale::where('install_id', $install->id)->delete();
                    $runs = SyncRun::where('install_id', $install->id);
                    foreach ((clone $runs)->select('id')->lazyById() as $run) {
                        $path = 'shopify/run-'.$run->id.'.jsonl';
                        $this->deleteFiles($path, $path.'.tmp');
                    }
                    (clone $runs)->update(['checkpoint' => null]);
                    $runs->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'completed_at' => now()]);
                }
                $orders = Order::where('store_id', $install->store_id);
                if ($shopRedaction) {
                    $orders->where('install_id', $install->id);
                } else {
                    $orders->whereIn('gid', $request->order_gids ?? []);
                }
                $orders->delete();
                if ($shopRedaction && $install->status !== 'redacted') {
                    $install->update(['tokens' => null, 'status' => 'redacted', 'generation' => $install->generation + 1]);
                    Models\Store::whereKey($install->store_id)->where('active_install_id', $install->id)->update([
                        'history_complete' => false, 'history_from' => null, 'synced_until' => null, 'last_synced_at' => null,
                    ]);
                }
            }
            if ($shopRedaction) {
                WebhookEvent::where('app_id', $request->app_id)->where('domain', $request->domain)->update(['payload' => null]);
            }
            $request->update(['status' => 'completed', 'completed_at' => $request->completed_at ?? now()]);
        });
    }

    private function deleteFiles(string ...$paths): void
    {
        if (! Storage::disk('local')->delete($paths)) {
            throw new ShopifyException('privacy_storage_failed', true);
        }
    }
}
