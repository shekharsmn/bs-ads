<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CollectAccountMetricsJob;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\ClientAccess;
use App\Services\GoogleAdsAccountDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ConnectionController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
            'ad_account_id' => 'nullable|integer|exists:ad_accounts,id',
            'platform' => 'nullable|in:google,meta',
            'status' => 'nullable|in:pending,healthy,error,disconnected',
        ]);
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        if ($filters['client_id'] ?? null) {
            ClientAccess::authorize($request->user(), Client::findOrFail($filters['client_id']));
        }
        if ($filters['ad_account_id'] ?? null) {
            $account = AdAccount::findOrFail($filters['ad_account_id']);
            ClientAccess::authorize($request->user(), Client::findOrFail($account->client_id));
            abort_if(($filters['client_id'] ?? null) && (int) $filters['client_id'] !== (int) $account->client_id, 422, 'The selected account does not belong to this client.');
        }

        return PlatformConnection::query()
            ->where(fn ($q) => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->whereHas('adAccounts', fn ($accounts) => $accounts->where('client_id', $id)))
            ->when($filters['ad_account_id'] ?? null, fn ($q, $id) => $q->whereHas('adAccounts', fn ($accounts) => $accounts->whereKey($id)))
            ->when($filters['platform'] ?? null, fn ($q, $value) => $q->where('platform', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->with([
                'client:id,name',
                'adAccounts' => fn ($q) => $q->whereIn('client_id', $clientIds)->select('id', 'client_id', 'platform_connection_id', 'external_id', 'manager_external_id', 'name', 'currency', 'timezone', 'status'),
                'adAccounts.client:id,name',
            ])
            ->withCount(['adAccounts' => fn ($q) => $q->whereIn('client_id', $clientIds)])
            ->orderBy('platform')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'client_id' => 'nullable|exists:clients,id', 'platform' => 'required|in:google,meta', 'name' => 'required|string|max:255',
            'scope' => 'required|in:agency,client', 'manager_external_id' => 'nullable|string|max:100', 'credentials' => 'required|array',
        ]);
        if ($data['scope'] === 'client') {
            $data['client_id'] = $data['client_id'] ?? abort(422, 'Client connection requires a client.');
        }
        if ($data['platform'] === 'meta') {
            $this->verifyMetaCredentials($data['credentials']);
        }

        return response()->json(PlatformConnection::create($data + ['status' => 'pending']), 201);
    }

    public function update(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'scope' => 'sometimes|in:agency,client',
            'client_id' => [Rule::requiredIf($request->input('scope', $connection->scope) === 'client'), 'nullable', 'integer', 'exists:clients,id'],
            'credentials' => 'sometimes|array',
            'credentials.access_token' => 'sometimes|required|string',
            'credentials.app_id' => 'nullable|string|max:255',
            'credentials.app_secret' => 'nullable|string|max:255',
            'manager_external_id' => 'nullable|string|max:100',
            'status' => 'sometimes|in:pending,healthy,error,disconnected',
        ]);

        $scope = $data['scope'] ?? $connection->scope;
        $clientId = $scope === 'client' ? (int) ($data['client_id'] ?? $connection->client_id) : null;
        if ($scope === 'client' && $connection->adAccounts()->where('client_id', '!=', $clientId)->exists()) {
            abort(422, 'Move or remove accounts assigned to other clients before limiting this connection to one client.');
        }

        $data['client_id'] = $clientId;
        if (array_key_exists('credentials', $data)) {
            abort_unless($connection->platform->value === 'meta', 422, 'Google credentials must be updated by reconnecting with Google.');
            $credentials = array_filter($data['credentials'], fn ($value) => $value !== null && $value !== '');
            $this->verifyMetaCredentials($credentials);
            $data['credentials'] = array_merge($connection->credentials ?? [], $credentials);
            $data['status'] = 'pending';
            $data['last_error'] = null;
        }
        $connection->update($data);

        return $connection->fresh();
    }

    public function discover(Request $request, PlatformConnection $connection, GoogleAdsAccountDiscovery $googleDiscovery)
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            $accounts = $connection->platform->value === 'google' ? $googleDiscovery->discover($connection) : $this->discoverMeta($connection);
            $assigned = $connection->adAccounts()->with('client:id,name')->get()->keyBy('external_id');
            $accounts = array_map(function (array $account) use ($assigned): array {
                $existing = $assigned->get($account['external_id']);
                $account['assigned_account_id'] = $existing?->id;
                $account['assigned_client'] = $existing?->client?->only(['id', 'name']);

                return $account;
            }, $accounts);
            $connection->update(['status' => 'healthy', 'last_synced_at' => now(), 'last_healthy_at' => now(), 'last_error' => null]);

            return ['accounts' => $accounts, 'warnings' => $connection->platform->value === 'google' ? $googleDiscovery->warnings() : []];
        } catch (\Throwable $e) {
            $connection->update(['status' => 'error', 'last_synced_at' => now(), 'last_error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function assignAccount(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['client_id' => 'required|exists:clients,id', 'external_id' => 'required|string', 'manager_external_id' => 'nullable|string', 'name' => 'required|string', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone:all_with_bc', 'monthly_budget' => 'nullable|numeric|min:0']);
        $this->ensureClientAssignment($connection, (int) $data['client_id']);
        $account = AdAccount::updateOrCreate(['platform_connection_id' => $connection->id, 'external_id' => $data['external_id']], $data + ['platform' => $connection->platform->value, 'status' => 'active']);
        CollectAccountMetricsJob::dispatch($account->id);

        return response()->json($account, 201);
    }

    public function assignAccounts(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'accounts' => 'required|array|min:1|max:500',
            'accounts.*.client_id' => 'required|exists:clients,id',
            'accounts.*.external_id' => 'required|string|max:100',
            'accounts.*.manager_external_id' => 'nullable|string|max:100',
            'accounts.*.name' => 'required|string|max:255',
            'accounts.*.currency' => 'required|string|size:3',
            'accounts.*.timezone' => 'required|timezone:all_with_bc',
            'accounts.*.monthly_budget' => 'nullable|numeric|min:0',
        ]);
        foreach ($data['accounts'] as $account) {
            $this->ensureClientAssignment($connection, (int) $account['client_id']);
        }

        $accounts = DB::transaction(function () use ($connection, $data) {
            return collect($data['accounts'])->map(function (array $account) use ($connection) {
                return AdAccount::updateOrCreate(
                    ['platform_connection_id' => $connection->id, 'external_id' => $account['external_id']],
                    $account + ['platform' => $connection->platform->value, 'status' => 'active']
                );
            });
        });
        $accounts->each(fn (AdAccount $account) => CollectAccountMetricsJob::dispatch($account->id));

        return response()->json(['accounts' => $accounts], 201);
    }

    public function destroy(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $connection->delete();

        return response()->noContent();
    }

    public function destroyAccount(Request $request, AdAccount $account)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $account->delete();

        return response()->noContent();
    }

    private function ensureClientAssignment(PlatformConnection $connection, int $clientId): void
    {
        if ($connection->scope === 'client' && (int) $connection->client_id !== $clientId) {
            abort(422, 'A client-specific connection can only assign accounts to its selected client.');
        }
    }

    private function discoverMeta(PlatformConnection $connection): array
    {
        $response = Http::timeout(30)->get('https://graph.facebook.com/v24.0/me/adaccounts', ['access_token' => $connection->credentials['access_token'], 'fields' => 'id,name,currency,timezone_name', 'limit' => 200]);
        if (! $response->successful()) {
            throw new RuntimeException($this->metaAccessError($response->status()));
        }

        return array_map(fn ($a) => [
            'external_id' => $a['id'],
            'name' => $a['name'],
            'currency' => $a['currency'] ?? null,
            'timezone' => $a['timezone_name'] ?? null,
            'is_manager' => false,
            'selectable' => true,
            'level' => 0,
            'manager_external_id' => null,
        ], $response->json('data', []));
    }

    private function verifyMetaCredentials(array $credentials): void
    {
        $token = $credentials['access_token'] ?? null;
        abort_unless(is_string($token) && trim($token) !== '', 422, 'A Meta System User access token is required.');

        try {
            $response = Http::timeout(30)->get('https://graph.facebook.com/v24.0/me/adaccounts', [
                'access_token' => $token,
                'fields' => 'id',
                'limit' => 1,
            ]);
        } catch (Throwable) {
            abort(422, 'Meta could not be reached to verify this token. Please try again.');
        }
        abort_unless($response->successful(), 422, $this->metaAccessError($response->status()));
    }

    private function metaAccessError(int $status): string
    {
        return in_array($status, [400, 401, 403], true)
            ? 'Meta rejected this token. Confirm the System User, assigned ad accounts, and ads_read permission, then generate a new token.'
            : 'Meta could not verify this token. Please try again.';
    }
}
