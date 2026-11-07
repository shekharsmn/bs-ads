<?php

namespace App\Shopify;

use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Installation;
use App\Shopify\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class InstallationService
{
    public function __construct(private readonly TokenProvider $tokens, private readonly GraphqlClient $api) {}

    public function establish(AppRegistration $app, string $domain, string $jwt): Installation
    {
        return Cache::lock('shop-auth:'.$app->id.':'.$domain, 60)->block(5, function () use ($app, $domain, $jwt) {
            $existing = Installation::where('app_id', $app->id)->whereHas('store', fn ($q) => $q->where('domain', $domain))->first();
            if ($existing?->status === 'active' && $existing->expires_at?->isAfter(now()->addMinute())) {
                $this->associateCustom($app, $existing->store);

                return $existing->fresh(['app', 'store']);
            }
            $data = $this->tokens->exchange($app, $domain, $jwt);
            $result = $this->api->send($domain, $data['access_token'], 'query { shop { id name myshopifyDomain ianaTimezone currencyCode plan { partnerDevelopment } } currentAppInstallation { accessScopes { handle } } }');
            $shop = $result['shop'] ?? [];
            if (($shop['myshopifyDomain'] ?? null) !== $domain || ! preg_match('~^gid://shopify/Shop/\d+$~', $shop['id'] ?? '')) {
                throw new ShopifyException('shop_identity_mismatch');
            }
            try {
                new \DateTimeZone($shop['ianaTimezone']);
            } catch (\Throwable) {
                throw new ShopifyException('invalid_shop_timezone');
            }

            return DB::transaction(function () use ($app, $domain, $existing, $data, $shop, $result) {
                if ($app->distribution === 'custom' && $app->client_id) {
                    \App\Models\Client::whereKey($app->client_id)->lockForUpdate()->firstOrFail();
                }
                $store = Store::firstOrCreate(['gid' => $shop['id']], ['domain' => $domain, 'name' => $shop['name'], 'timezone' => $shop['ianaTimezone'], 'currency' => $shop['currencyCode']]);
                $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $existing ??= Installation::where('app_id', $app->id)->where('store_id', $store->id)->first();
                if ($store->domain !== $domain) {
                    Models\PrivacyRequest::where('domain', $store->domain)->update(['domain' => $domain]);
                }
                if ($app->client_id && $store->client_id && $store->client_id !== $app->client_id) {
                    throw new ShopifyException('store_already_linked');
                }
                $store->update(['domain' => $domain, 'name' => $shop['name'], 'timezone' => $shop['ianaTimezone'], 'currency' => $shop['currencyCode'], 'is_development' => data_get($shop, 'plan.partnerDevelopment') === true]);
                $this->associateCustom($app, $store);
                $install = $existing ?? Installation::create(['app_id' => $app->id, 'store_id' => $store->id, 'installed_at' => now()]);
                if ($existing && $existing->status === 'superseded') {
                    throw new ShopifyException('installation_superseded');
                }
                if ($existing && in_array($existing->status, ['revoked', 'redacted'], true)) {
                    $install->update(['generation' => $install->generation + 1, 'revoked_at' => null, 'installed_at' => now()]);
                }
                $data['scope'] = implode(',', array_column($result['currentAppInstallation']['accessScopes'] ?? [], 'handle'));
                $this->tokens->save($install, $data);
                if (! $store->active_install_id) {
                    $store->update(['active_install_id' => $install->id]);
                }

                return $install->fresh(['app', 'store']);
            });
        });
    }

    private function associateCustom(AppRegistration $app, Store $store): void
    {
        if ($app->distribution !== 'custom' || ! $app->client_id) {
            return;
        }
        // Operator preassignment authorizes the agency side; verified installation supplies merchant consent.
        // Public registrations continue to require the separate two-party linking intent.
        DB::transaction(function () use ($app, $store) {
            \App\Models\Client::whereKey($app->client_id)->lockForUpdate()->firstOrFail();
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if ($store->client_id && $store->client_id !== $app->client_id) {
                throw new ShopifyException('store_already_linked');
            }
            if (Store::where('client_id', $app->client_id)->where('id', '!=', $store->id)->exists()) {
                throw new ShopifyException('replacement_confirmation_required');
            }
            $store->update(['client_id' => $app->client_id]);
        });
    }

    public function revoke(Installation $install): void
    {
        DB::transaction(function () use ($install) {
            $install = Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            $install->update(['status' => 'revoked', 'tokens' => null, 'generation' => $install->generation + 1, 'revoked_at' => now()]);
            Models\SyncRun::where('install_id', $install->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'completed_at' => now()]);
        });
    }
}
