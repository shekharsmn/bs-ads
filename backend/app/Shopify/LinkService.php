<?php

namespace App\Shopify;

use App\Models\Client;
use App\Models\User;
use App\Shopify\Models\Installation;
use App\Shopify\Models\LinkIntent;
use App\Shopify\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LinkService
{
    public function create(Client $client, User $user, bool $replace): string
    {
        $token = Str::random(48);
        LinkIntent::create(['client_id' => $client->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15), 'replace' => $replace]);

        return $token;
    }

    public function confirm(Installation $install, string $token): void
    {
        DB::transaction(function () use ($install, $token) {
            $intent = LinkIntent::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $intent || $intent->used_at || $intent->expires_at->isPast()) {
                throw new ShopifyException('link_expired');
            }
            $user = User::find($intent->user_id);
            if (! $user?->isAdmin() || $user->disabled_at) {
                throw new ShopifyException('link_unauthorized');
            }
            Client::whereKey($intent->client_id)->lockForUpdate()->firstOrFail();
            if ($install->app->client_id && $install->app->client_id !== $intent->client_id) {
                throw new ShopifyException('wrong_client');
            }
            $store = Store::whereKey($install->store_id)->lockForUpdate()->firstOrFail();
            if ($store->client_id && $store->client_id !== $intent->client_id) {
                throw new ShopifyException('store_already_linked');
            }
            $old = Store::where('client_id', $intent->client_id)->where('id', '!=', $store->id)->lockForUpdate()->first();
            if ($old && ! $intent->replace) {
                throw new ShopifyException('replacement_confirmation_required');
            }
            if ($old) {
                $old->update(['client_id' => null]);
            }
            $store->update(['client_id' => $intent->client_id]);
            $intent->update(['used_at' => now()]);
        });
    }
}
