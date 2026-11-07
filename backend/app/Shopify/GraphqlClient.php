<?php

namespace App\Shopify;

use App\Shopify\Models\Installation;
use Illuminate\Support\Facades\Http;

final class GraphqlClient
{
    public function __construct(private readonly TokenProvider $tokens) {}

    public function query(Installation $install, string $query, array $variables = []): array
    {
        try {
            return $this->send($install->store->domain, $this->tokens->access($install), $query, $variables);
        } catch (ShopifyException $e) {
            if ($e->errorCode === 'api_http_401') {
                $install->update(['status' => 'reconnect', 'error_code' => 'token_rejected']);
            }
            throw $e;
        }
    }

    public function send(string $domain, string $token, string $query, array $variables = []): array
    {
        $domain = Identity::domain($domain);
        $throttleKey = 'shop-throttle:'.$domain;
        if ((int) \Illuminate\Support\Facades\Cache::get($throttleKey, 0) > now()->timestamp) {
            throw new ShopifyException('throttled', true);
        }
        try {
            $response = Http::connectTimeout(5)->timeout(config('shopify.http_timeout'))->withOptions(['allow_redirects' => false])
                ->withHeaders(['X-Shopify-Access-Token' => $token])->post('https://'.$domain.'/admin/api/'.config('shopify.api_version').'/graphql.json',
                    ['query' => $query, 'variables' => (object) $variables]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new ShopifyException('api_network', true);
        }
        if ($response->status() === 429) {
            \Illuminate\Support\Facades\Cache::put($throttleKey, now()->timestamp + max(1, (int) $response->header('Retry-After')), max(1, (int) $response->header('Retry-After')));
        }
        if (! $response->successful()) {
            throw new ShopifyException('api_http_'.$response->status(), $response->status() === 429 || $response->serverError());
        }
        $body = $response->json();
        $cost = data_get($body, 'extensions.cost');
        if (is_array($cost)) {
            $available = (float) data_get($cost, 'throttleStatus.currentlyAvailable', 1000);
            $restore = (float) data_get($cost, 'throttleStatus.restoreRate', 100);
            $needed = (float) ($cost['requestedQueryCost'] ?? 100);
            if ($available < $needed && $restore > 0) {
                $wait = (int) ceil(($needed - $available) / $restore);
                \Illuminate\Support\Facades\Cache::put($throttleKey, now()->timestamp + $wait, $wait);
            }
        }
        if (! is_array($body) || ! empty($body['errors'])) {
            $code = data_get($body, 'errors.0.extensions.code', 'graphql_error');
            throw new ShopifyException(in_array($code, ['THROTTLED', 'ACCESS_DENIED']) ? strtolower($code) : 'graphql_error', $code === 'THROTTLED');
        }
        if (! isset($body['data'])) {
            throw new ShopifyException('invalid_api_response');
        }

        return $body['data'];
    }
}
