<?php

namespace Tests\Feature;

use App\Jobs\CollectAccountMetricsJob;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_account_performance_never_blends_currencies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'INR']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy']);
        $inr = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'INR', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        $usd = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '2', 'name' => 'USD', 'currency' => 'USD', 'timezone' => 'America/New_York']);
        foreach ([[$inr, 'INR', 100], [$usd, 'USD', 10]] as [$account, $currency, $spend]) {
            MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => '2026-10-15', 'currency' => $currency, 'spend' => $spend, 'collected_at' => now()]);
        }

        $response = $this->actingAs($admin)->getJson('/api/performance/accounts')->assertOk();
        $this->assertCount(2, $response->json('accounts'));
        $this->assertEqualsCanonicalizing(['INR', 'USD'], collect($response->json('accounts'))->pluck('currency')->all());
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

    public function test_meta_connection_is_verified_before_it_is_saved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['id' => 'act_123']]])]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/connections', [
            'platform' => 'meta', 'name' => 'Agency Meta', 'scope' => 'agency',
            'credentials' => ['access_token' => 'valid-system-user-token'],
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('platform_connections', ['platform' => 'meta', 'name' => 'Agency Meta']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/v24.0/me/adaccounts')
            && $request['access_token'] === 'valid-system-user-token'
            && $request['limit'] === 1);
    }

    public function test_rejected_meta_token_is_redacted_and_not_saved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Malformed access token SECRET_TOKEN_VALUE', 'code' => 190],
        ], 400)]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/connections', [
            'platform' => 'meta', 'name' => 'Broken Meta', 'scope' => 'agency',
            'credentials' => ['access_token' => 'SECRET_TOKEN_VALUE'],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Meta rejected this token. Confirm the System User, assigned ad accounts, and ads_read permission, then generate a new token.');

        $this->assertStringNotContainsString('SECRET_TOKEN_VALUE', $response->getContent());
        $this->assertDatabaseMissing('platform_connections', ['name' => 'Broken Meta']);
    }

    public function test_client_can_be_edited_without_exposing_or_replacing_saved_webhook(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $webhook = 'https://chat.googleapis.com/v1/spaces/a/messages?key=secret';
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'google_chat_webhook' => $webhook]);

        $this->actingAs($admin)->putJson('/api/clients/'.$client->id, [
            'name' => 'Acme Updated',
            'timezone' => 'Asia/Dubai',
            'currency' => 'AED',
        ])->assertOk()->assertJsonPath('name', 'Acme Updated')->assertJsonMissingPath('google_chat_webhook');

        $client->refresh();
        $this->assertSame($webhook, $client->google_chat_webhook);
        $this->assertSame('Asia/Dubai', $client->timezone);
        $this->assertSame('AED', $client->currency);

        $this->actingAs($admin)->putJson('/api/clients/'.$client->id, [
            'google_chat_webhook' => null,
        ])->assertOk()->assertJsonPath('google_chat_webhook_configured', false);
    }

    public function test_discovered_accounts_can_be_assigned_to_clients_in_bulk_with_manager_context(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'AED', 'timezone' => 'Asia/Dubai']);
        $connection = PlatformConnection::create([
            'platform' => 'google',
            'name' => 'Agency MCC',
            'scope' => 'agency',
            'credentials' => ['refresh_token' => 'secret'],
            'status' => 'healthy',
        ]);

        $this->actingAs($admin)->postJson('/api/connections/'.$connection->id.'/accounts/bulk', [
            'accounts' => [[
                'client_id' => $client->id,
                'external_id' => '1234567890',
                'manager_external_id' => '9999999999',
                'name' => 'Acme Search',
                'currency' => 'AED',
                'timezone' => 'Asia/Calcutta',
            ]],
        ])->assertCreated()->assertJsonPath('accounts.0.manager_external_id', '9999999999');

        $this->assertDatabaseHas('ad_accounts', [
            'platform_connection_id' => $connection->id,
            'client_id' => $client->id,
            'external_id' => '1234567890',
            'manager_external_id' => '9999999999',
            'timezone' => 'Asia/Calcutta',
        ]);
        Queue::assertPushed(CollectAccountMetricsJob::class, fn (CollectAccountMetricsJob $job) => $job->adAccountId === AdAccount::query()->where('external_id', '1234567890')->value('id'));
    }

    public function test_agency_connection_only_exposes_accounts_for_assigned_clients(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $assignedClient = Client::create(['name' => 'Assigned', 'slug' => 'assigned']);
        $hiddenClient = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $assignedClient->users()->attach($viewer);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret']]);
        AdAccount::create(['client_id' => $assignedClient->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'Visible', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        AdAccount::create(['client_id' => $hiddenClient->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '2', 'name' => 'Hidden', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);

        $this->actingAs($viewer)->getJson('/api/connections')
            ->assertOk()
            ->assertJsonPath('0.ad_accounts_count', 1)
            ->assertJsonCount(1, '0.ad_accounts')
            ->assertJsonPath('0.ad_accounts.0.name', 'Visible');
    }

    public function test_admin_can_remove_a_connection_and_its_assigned_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'Old MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret']]);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1234567890', 'name' => 'Acme Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);

        $this->actingAs($viewer)->deleteJson('/api/connections/'.$connection->id)->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/connections/'.$connection->id)->assertNoContent();

        $this->assertDatabaseMissing('platform_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('ad_accounts', ['id' => $account->id]);
    }

    public function test_only_admin_can_delete_an_assigned_ad_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $client->users()->attach($viewer);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => 'act-delete', 'name' => 'Delete me', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->actingAs($viewer)->deleteJson('/api/ad-accounts/'.$account->id)->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/ad-accounts/'.$account->id)->assertNoContent();

        $this->assertDatabaseMissing('ad_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('platform_connections', ['id' => $connection->id]);
    }

    public function test_only_admin_can_delete_a_client_and_connections_are_retained(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $marketer = User::factory()->create(['role' => 'marketer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $client->users()->attach($marketer);
        $connection = PlatformConnection::create([
            'client_id' => $client->id,
            'platform' => 'meta',
            'name' => 'Acme Meta',
            'scope' => 'client',
            'credentials' => [],
            'status' => 'healthy',
        ]);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => 'act-client-delete', 'name' => 'Acme Ads', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->actingAs($marketer)->deleteJson('/api/clients/'.$client->id)->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/clients/'.$client->id)->assertNoContent();

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('ad_accounts', ['id' => $account->id]);
        $this->assertDatabaseHas('platform_connections', ['id' => $connection->id, 'client_id' => null]);
    }

    public function test_client_specific_connection_cannot_assign_an_account_to_another_client(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $selectedClient = Client::create(['name' => 'Selected', 'slug' => 'selected']);
        $otherClient = Client::create(['name' => 'Other', 'slug' => 'other']);
        $connection = PlatformConnection::create([
            'client_id' => $selectedClient->id,
            'platform' => 'google',
            'name' => 'Selected client only',
            'scope' => 'client',
            'credentials' => ['refresh_token' => 'secret'],
        ]);

        $this->actingAs($admin)->postJson('/api/connections/'.$connection->id.'/accounts/bulk', [
            'accounts' => [[
                'client_id' => $otherClient->id,
                'external_id' => '1234567890',
                'manager_external_id' => null,
                'name' => 'Wrong client',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
            ]],
        ])->assertUnprocessable()->assertJsonPath('message', 'A client-specific connection can only assign accounts to its selected client.');

        $this->assertDatabaseCount('ad_accounts', 0);
    }
}
