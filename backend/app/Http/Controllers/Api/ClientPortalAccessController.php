<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientPortalAccountScope;
use App\Models\UserInvitation;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientPortalAccessController extends Controller
{
    public function show(Client $client)
    {
        return $this->payload($client);
    }

    public function update(Request $request, Client $client, AuditTrail $audit)
    {
        $data = $request->validate([
            'portal_enabled' => 'required|boolean',
            'accounts' => 'required|array',
            'accounts.*.ad_account_id' => 'required|integer|distinct|exists:ad_accounts,id',
            'accounts.*.visibility_mode' => 'required|in:hidden,all_campaigns,selected_campaigns',
            'accounts.*.campaign_ids' => 'present|array',
            'accounts.*.campaign_ids.*' => 'integer|distinct|exists:campaigns,id',
        ]);

        $accountIds = $client->adAccounts()->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($data['accounts'] as $index => $account) {
            if (! $accountIds->contains((int) $account['ad_account_id'])) {
                throw ValidationException::withMessages(["accounts.{$index}.ad_account_id" => 'The ad account does not belong to this client.']);
            }
            if ($account['visibility_mode'] === ClientPortalAccountScope::SELECTED_CAMPAIGNS && $account['campaign_ids'] === []) {
                throw ValidationException::withMessages(["accounts.{$index}.campaign_ids" => 'Select at least one campaign.']);
            }
            $validCampaigns = Campaign::query()->where('ad_account_id', $account['ad_account_id'])->whereIn('id', $account['campaign_ids'])->count();
            if ($validCampaigns !== count($account['campaign_ids'])) {
                throw ValidationException::withMessages(["accounts.{$index}.campaign_ids" => 'Every campaign must belong to its ad account.']);
            }
        }

        $before = $this->payload($client);
        DB::transaction(function () use ($client, $data, $accountIds) {
            $client->update(['portal_enabled' => $data['portal_enabled']]);
            $submitted = collect($data['accounts'])->keyBy(fn ($row) => (int) $row['ad_account_id']);
            foreach ($accountIds as $accountId) {
                $row = $submitted->get($accountId, ['visibility_mode' => ClientPortalAccountScope::HIDDEN, 'campaign_ids' => []]);
                $scope = ClientPortalAccountScope::updateOrCreate(
                    ['client_id' => $client->id, 'ad_account_id' => $accountId],
                    ['visibility_mode' => $row['visibility_mode']]
                );
                $scope->campaigns()->sync($row['visibility_mode'] === ClientPortalAccountScope::SELECTED_CAMPAIGNS ? $row['campaign_ids'] : []);
            }
        });

        $after = $this->payload($client->fresh());
        $audit->record('access.client_portal_policy_updated', $client, $before, $after, $client->id, $request->user(), $request);

        return $after;
    }

    private function payload(Client $client): array
    {
        $scopes = $client->portalAccountScopes()->with('campaigns:id')->get()->keyBy('ad_account_id');
        $accounts = $client->adAccounts()->with(['campaigns' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get();
        $users = $client->users()->where('role', UserRole::Client->value)->orderBy('name')->get(['users.id', 'name', 'email', 'disabled_at', 'last_login_at']);
        $invitations = UserInvitation::query()
            ->where('role', UserRole::Client->value)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereHas('clients', fn ($query) => $query->whereKey($client->id))
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'delivery_error', 'last_sent_at']);

        return [
            'client_id' => $client->id,
            'portal_enabled' => (bool) $client->portal_enabled,
            'client_users' => $users->map(fn ($user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'status' => $user->disabled_at ? 'disabled' : 'active', 'last_login_at' => $user->last_login_at,
            ])->values(),
            'client_invitations' => $invitations->map(fn ($invitation) => [
                'id' => $invitation->id, 'name' => $invitation->name, 'email' => $invitation->email,
                'status' => 'pending', 'delivery_error' => $invitation->delivery_error, 'last_sent_at' => $invitation->last_sent_at,
            ])->values(),
            'accounts' => $accounts->map(function ($account) use ($scopes) {
                $scope = $scopes->get($account->id);
                return [
                    'ad_account' => $account->only(['id', 'name', 'platform', 'external_id', 'currency', 'timezone', 'status']),
                    'visibility_mode' => $scope?->visibility_mode ?? ClientPortalAccountScope::HIDDEN,
                    'campaign_ids' => $scope?->campaigns->pluck('id')->map(fn ($id) => (int) $id)->values() ?? [],
                    'campaigns' => $account->campaigns->map->only(['id', 'name', 'status', 'type', 'external_id'])->values(),
                ];
            })->values(),
        ];
    }
}
