<?php

namespace App\Shopify;

use App\Shopify\Models\Installation;
use App\Shopify\Models\Order;
use App\Shopify\Models\PrivacyRequest;
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
                $this->erase($request);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    public function erase(PrivacyRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $install = Installation::where('app_id', $request->app_id)->whereHas('store', fn ($q) => $q->where('domain', $request->domain))->first();
            if ($install) {
                if ($request->topic === 'shop/redact') {
                    \App\Shopify\Models\Sale::where('install_id', $install->id)->delete();
                }
                $orders = Order::where('store_id', $install->store_id);
                if ($request->topic === 'shop/redact') {
                    $orders->where('install_id', $install->id);
                } else {
                    $orders->whereIn('gid', $request->order_gids ?? []);
                }
                $orders->delete();
                if ($request->topic === 'shop/redact' && $install->status !== 'redacted') {
                    $install->update(['tokens' => null, 'status' => 'redacted', 'generation' => $install->generation + 1]);
                }
            }
            $request->update(['status' => 'completed', 'completed_at' => $request->completed_at ?? now()]);
        });
    }
}
