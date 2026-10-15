<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\ClientAccess;
use App\Services\GoogleAdsClientFactory;
use Google\Ads\GoogleAds\V23\Services\ListAccessibleCustomersRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ConnectionController extends Controller
{
    public function index(Request $request)
    {
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        return PlatformConnection::query()->where(fn ($q) => $q->whereNull('client_id')->orWhereIn('client_id', $clientIds))->withCount('adAccounts')->get();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'client_id' => 'nullable|exists:clients,id', 'platform' => 'required|in:google,meta', 'name' => 'required|string|max:255',
            'scope' => 'required|in:agency,client', 'manager_external_id' => 'nullable|string|max:100', 'credentials' => 'required|array',
        ]);
        if ($data['scope'] === 'client') $data['client_id'] = $data['client_id'] ?? abort(422, 'Client connection requires a client.');
        return response()->json(PlatformConnection::create($data + ['status' => 'pending']), 201);
    }

    public function update(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'credentials' => 'sometimes|array', 'manager_external_id' => 'nullable|string|max:100', 'status' => 'sometimes|in:pending,healthy,error,disconnected']);
        $connection->update($data);
        return $connection->fresh();
    }

    public function discover(Request $request, PlatformConnection $connection)
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            $accounts = $connection->platform->value === 'google' ? $this->discoverGoogle($connection) : $this->discoverMeta($connection);
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
        $data = $request->validate(['client_id' => 'required|exists:clients,id', 'external_id' => 'required|string', 'name' => 'required|string', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone', 'monthly_budget' => 'nullable|numeric|min:0']);
        $account = AdAccount::updateOrCreate(['platform_connection_id' => $connection->id, 'external_id' => $data['external_id']], $data + ['platform' => $connection->platform->value, 'status' => 'active']);
        return response()->json($account, 201);
    }

    private function discoverGoogle(PlatformConnection $connection): array
    {
        $c = $connection->credentials;
        $client = GoogleAdsClientFactory::fromRefreshToken($c['refresh_token'], $c['login_customer_id'] ?? $connection->manager_external_id);
        $response = $client->getCustomerServiceClient()->listAccessibleCustomers(new ListAccessibleCustomersRequest);
        return array_map(fn ($name) => ['external_id' => basename($name), 'name' => basename($name), 'currency' => null, 'timezone' => null], iterator_to_array($response->getResourceNames()));
    }

    private function discoverMeta(PlatformConnection $connection): array
    {
        $response = Http::timeout(30)->get('https://graph.facebook.com/v24.0/me/adaccounts', ['access_token' => $connection->credentials['access_token'], 'fields' => 'id,name,currency,timezone_name', 'limit' => 200]);
        $response->throw();
        return array_map(fn ($a) => ['external_id' => $a['id'], 'name' => $a['name'], 'currency' => $a['currency'] ?? null, 'timezone' => $a['timezone_name'] ?? null], $response->json('data', []));
    }
}
