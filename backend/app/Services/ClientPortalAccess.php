<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientPortalAccountScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ClientPortalAccess
{
    public function clientsFor(User $user): Collection
    {
        if ($user->isAdmin()) {
            return Client::query()->where('active', true)->where('portal_enabled', true)->orderBy('name')->get();
        }
        abort_unless($user->isClient(), 403);

        return $user->clients()->where('clients.active', true)->where('clients.portal_enabled', true)->orderBy('clients.name')->get();
    }

    public function authorizeClient(User $user, Client $client): Client
    {
        if ($user->isAdmin()) {
            abort_unless($client->active && $client->portal_enabled, 404);
            return $client;
        }
        abort_unless(
            $user->isClient()
            && $client->active
            && $client->portal_enabled
            && $user->clients()->whereKey($client->id)->exists(),
            404
        );

        return $client;
    }

    public function scopeFor(User $user, AdAccount $account): ClientPortalAccountScope
    {
        $this->authorizeClient($user, $account->client()->firstOrFail());
        $scope = ClientPortalAccountScope::query()
            ->with('campaigns:id,ad_account_id,name,status,type,external_id')
            ->where('client_id', $account->client_id)
            ->where('ad_account_id', $account->id)
            ->whereNot('visibility_mode', ClientPortalAccountScope::HIDDEN)
            ->first();

        abort_unless($scope, 404);

        return $scope;
    }

    public function authorizeCampaign(ClientPortalAccountScope $scope, Campaign $campaign): void
    {
        abort_unless((int) $campaign->ad_account_id === (int) $scope->ad_account_id, 404);
        if ($scope->visibility_mode === ClientPortalAccountScope::SELECTED_CAMPAIGNS) {
            abort_unless($scope->campaigns->contains('id', $campaign->id), 404);
        }
    }
}
