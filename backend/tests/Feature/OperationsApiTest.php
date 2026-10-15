<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_only_sees_assigned_clients_and_cannot_mutate(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $assigned = Client::create(['name' => 'Assigned', 'slug' => 'assigned']);
        Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $assigned->users()->attach($viewer);

        $this->actingAs($viewer)->getJson('/api/clients')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Assigned');
        $this->actingAs($viewer)->putJson('/api/clients/'.$assigned->id, ['name' => 'Nope'])->assertForbidden();
    }

    public function test_metric_summary_never_blends_currencies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'INR']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy']);
        $inr = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'INR', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        $usd = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '2', 'name' => 'USD', 'currency' => 'USD', 'timezone' => 'America/New_York']);
        foreach ([[$inr, 'INR', 100], [$usd, 'USD', 10]] as [$account, $currency, $spend]) {
            MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => '2026-10-15', 'currency' => $currency, 'spend' => $spend, 'collected_at' => now()]);
        }

        $response = $this->actingAs($admin)->getJson('/api/metrics/summary?client_id='.$client->id.'&start_date=2026-10-01&end_date=2026-10-31')->assertOk();
        $this->assertCount(2, $response->json('currencies'));
        $this->assertEqualsCanonicalizing(['INR', 'USD'], collect($response->json('currencies'))->pluck('currency')->all());
    }

    public function test_secrets_are_encrypted_and_redacted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'google_chat_webhook' => 'https://chat.googleapis.com/v1/spaces/a/messages?key=secret']);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency', 'credentials' => ['access_token' => 'top-secret'], 'status' => 'healthy']);

        $this->assertStringNotContainsString('top-secret', $connection->getRawOriginal('credentials'));
        $this->actingAs($admin)->getJson('/api/clients/'.$client->id)->assertOk()->assertJsonMissing(['google_chat_webhook' => $client->google_chat_webhook])->assertJsonPath('google_chat_webhook_configured', true);
        $this->actingAs($admin)->getJson('/api/connections')->assertOk()->assertJsonMissing(['credentials' => ['access_token' => 'top-secret']]);
    }
}
