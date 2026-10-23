<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\PlatformConnection;
use App\Services\ClientAccess;
use App\Services\GoogleAdsAccountDiscovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ConnectionController extends Controller
{
    public function index(Request $request)
    {
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');

        return PlatformConnection::query()
            ->where(fn ($q) => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))
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

        return response()->json(PlatformConnection::create($data + ['status' => 'pending']), 201);
    }

    public function update(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'credentials' => 'sometimes|array', 'manager_external_id' => 'nullable|string|max:100', 'status' => 'sometimes|in:pending,healthy,error,disconnected']);
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

            return ['accounts' => $accounts];
        } catch (\Throwable $e) {
            $connection->update(['status' => 'error', 'last_synced_at' => now(), 'last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function assignAccount(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['client_id' => 'required|exists:clients,id', 'external_id' => 'required|string', 'manager_external_id' => 'nullable|string', 'name' => 'required|string', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone:all_with_bc', 'monthly_budget' => 'nullable|numeric|min:0']);
        $account = AdAccount::updateOrCreate(['platform_connection_id' => $connection->id, 'external_id' => $data['external_id']], $data + ['platform' => $connection->platform->value, 'status' => 'active']);

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

        $accounts = DB::transaction(function () use ($connection, $data) {
            return collect($data['accounts'])->map(function (array $account) use ($connection) {
                return AdAccount::updateOrCreate(
                    ['platform_connection_id' => $connection->id, 'external_id' => $account['external_id']],
                    $account + ['platform' => $connection->platform->value, 'status' => 'active']
                );
            });
        });

        return response()->json(['accounts' => $accounts], 201);
    }

    private function discoverMeta(PlatformConnection $connection): array
    {
        $response = Http::timeout(30)->get('https://graph.facebook.com/v24.0/me/adaccounts', ['access_token' => $connection->credentials['access_token'], 'fields' => 'id,name,currency,timezone_name', 'limit' => 200]);
        $response->throw();

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
}
