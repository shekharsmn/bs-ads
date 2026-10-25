<?php

namespace App\Services;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Support\ResolvedClientScope;
use Illuminate\Validation\ValidationException;

class ClientScopeResolver
{
    public function resolve(User $user, array $scope, bool $manage = false, bool $allowGlobal = false): ResolvedClientScope
    {
        $campaign = isset($scope['campaign_id']) && $scope['campaign_id'] !== null
            ? Campaign::with('adAccount')->findOrFail($scope['campaign_id'])
            : null;
        $account = isset($scope['ad_account_id']) && $scope['ad_account_id'] !== null
            ? AdAccount::findOrFail($scope['ad_account_id'])
            : $campaign?->adAccount;

        $clientIds = collect([
            $scope['client_id'] ?? null,
            $account?->client_id,
            $campaign?->adAccount?->client_id,
        ])->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values();

        if ($clientIds->count() > 1) {
            throw ValidationException::withMessages([
                'scope' => 'The selected client, account, and campaign must belong to the same client.',
            ]);
        }
        if ($clientIds->isEmpty()) {
            abort_unless($allowGlobal && $user->isAdmin(), 403);

            return new ResolvedClientScope(null);
        }

        $client = ClientAccess::authorize($user, Client::findOrFail($clientIds->first()), $manage);

        return new ResolvedClientScope($client, $account, $campaign);
    }
}
