<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectorAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_google_account_requires_client_access(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $account = $this->googleAccount($client, ['refresh_token' => 'secret']);

        $this->actingAs($viewer)->postJson('/api/inspect', [
            'platform' => 'google',
            'account_source' => 'operational',
            'account_id' => $account->id,
            'period' => 'last_30d',
        ])->assertForbidden();
    }

    public function test_operational_google_account_reports_missing_connection_credentials_cleanly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $account = $this->googleAccount($client, []);

        $this->actingAs($admin)->postJson('/api/inspect', [
            'platform' => 'google',
            'account_id' => $account->id,
            'period' => 'last_30d',
        ])->assertStatus(422)->assertSee('Reconnect it from Ad Platforms');
    }

    private function googleAccount(Client $client, array $credentials): AdAccount
    {
        $connection = PlatformConnection::create([
            'platform' => 'google',
            'name' => 'Agency MCC',
            'scope' => 'agency',
            'credentials' => $credentials,
            'status' => 'healthy',
        ]);

        return AdAccount::create([
            'client_id' => $client->id,
            'platform_connection_id' => $connection->id,
            'platform' => 'google',
            'external_id' => '1234567890',
            'manager_external_id' => '9999999999',
            'name' => 'Client account',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);
    }
}
