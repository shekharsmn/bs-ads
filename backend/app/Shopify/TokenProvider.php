<?php

namespace App\Shopify;

use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class TokenProvider
{
    public function exchange(AppRegistration $app, string $domain, string $idToken): array
    {
        return $this->request($app, $domain, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken, 'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token', 'expiring' => '1',
        ]);
    }

    private function request(AppRegistration $app, string $domain, array $fields): array
    {
        $domain = Identity::domain($domain);
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(config('shopify.http_timeout'))
                ->withOptions(['allow_redirects' => false])->post("https://{$domain}/admin/oauth/access_token", $fields + [
                    'client_id' => $app->client_key, 'client_secret' => $app->secret,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new ShopifyException('token_network', true);
        }
        if (! $response->successful()) {
            throw new ShopifyException('token_rejected', $response->status() === 429 || $response->serverError());
        }
        $data = $response->json();
        foreach (['access_token', 'refresh_token', 'expires_in', 'refresh_token_expires_in'] as $field) {
            if (empty($data[$field])) {
                throw new ShopifyException('invalid_token_response');
            }
        }

        return $data;
    }

    public function save(Installation $installation, array $data): void
    {
        $installation->update([
            'tokens' => ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token']],
            'expires_at' => now()->addSeconds((int) $data['expires_in']),
            'refresh_expires_at' => now()->addSeconds((int) $data['refresh_token_expires_in']),
            'scopes' => array_values(array_filter(explode(',', $data['scope'] ?? implode(',', $installation->scopes ?? [])))),
            'status' => 'active', 'error_code' => null,
        ]);
    }

    public function access(Installation $installation): string
    {
        return Cache::lock('shop-auth:'.$installation->app_id.':'.$installation->store->domain, 60)->block(5, function () use ($installation) {
            $installation->refresh();
            if ($installation->status !== 'active' || ! $installation->tokens || ! $installation->app->active) {
                throw new ShopifyException('reconnect_required');
            }
            if ($installation->expires_at?->isAfter(now()->addMinute())) {
                return $installation->tokens['access_token'];
            }
            if (! $installation->refresh_expires_at || $installation->refresh_expires_at->isPast()) {
                $installation->update(['status' => 'reconnect', 'error_code' => 'refresh_expired']);
                throw new ShopifyException('reconnect_required');
            }
            try {
                $data = $this->request($installation->app, $installation->store->domain, ['grant_type' => 'refresh_token', 'refresh_token' => $installation->tokens['refresh_token']]);
            } catch (ShopifyException $e) {
                if (! $e->retryable) {
                    $installation->update(['status' => 'reconnect', 'error_code' => $e->errorCode]);
                }
                throw $e;
            }
            $this->save($installation, $data);

            return $data['access_token'];
        });
    }
}
