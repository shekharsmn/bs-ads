<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportSnapshotJob;
use App\Jobs\SendNotificationJob;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\DataQualityIssue;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\AdLibraryAudit\SafePublicUrl;
use App\Services\DataQualityMonitor;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GovernanceScalingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_access_and_change_is_recorded_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)->putJson('/api/governance/users/'.$viewer->id, ['role' => 'marketer', 'client_ids' => [$client->id]])
            ->assertOk()->assertJsonPath('role', 'marketer')->assertJsonPath('clients.0.id', $client->id);

        $this->assertDatabaseHas('client_user', ['client_id' => $client->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'access.user_updated', 'entity_id' => (string) $viewer->id]);
    }

    public function test_last_admin_cannot_be_disabled_and_disabled_users_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/governance/users/'.$admin->id, ['disabled' => true])->assertUnprocessable();

        $viewer = User::factory()->create(['role' => 'viewer', 'disabled_at' => now(), 'disabled_by' => $admin->id]);
        $this->actingAs($viewer)->getJson('/api/clients')->assertForbidden();
    }

    public function test_data_quality_monitor_deduplicates_and_api_honors_client_access(): void
    {
        Queue::fake();
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD', 'timezone' => 'UTC']);
        $hidden = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $client->users()->attach($viewer);
        $connection = PlatformConnection::create(['client_id' => $client->id, 'platform' => 'meta', 'name' => 'Meta', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => '1', 'name' => 'Acme Ads', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'status' => 'active']);
        app(DataQualityMonitor::class)->inspect($account->fresh('client'));
        app(DataQualityMonitor::class)->inspect($account->fresh('client'));
        DataQualityIssue::create(['client_id' => $hidden->id, 'issue_key' => 'hidden:stale', 'type' => 'stale_collection', 'severity' => 'warning', 'status' => 'open', 'title' => 'Hidden', 'first_detected_at' => now(), 'last_detected_at' => now()]);

        $this->assertDatabaseCount('data_quality_issues', 3);
        $response = $this->actingAs($viewer)->getJson('/api/data-quality/issues')->assertOk();
        $this->assertNotContains('Hidden', collect($response->json('data'))->pluck('title'));
    }

    public function test_data_quality_monitor_notifies_when_a_resolved_issue_reopens(): void
    {
        $dispatcher = $this->mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturn(1);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD', 'timezone' => 'UTC']);
        $connection = PlatformConnection::create(['client_id' => $client->id, 'platform' => 'meta', 'name' => 'Meta', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => 'reopen', 'name' => 'Acme Ads', 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        $monitor = app(DataQualityMonitor::class);

        $monitor->inspect($account->fresh('client'));
        $account->update(['last_collected_at' => now()]);
        $monitor->inspect($account->fresh('client'));
        $this->assertDatabaseHas('data_quality_issues', ['issue_key' => $account->id.':stale_collection', 'status' => 'resolved']);

        $account->update(['last_collected_at' => now()->subHours(3)]);
        $monitor->inspect($account->fresh('client'));
        $monitor->inspect($account->fresh('client'));

        $this->assertDatabaseHas('data_quality_issues', ['issue_key' => $account->id.':stale_collection', 'status' => 'open']);
    }

    public function test_reports_store_methodology_and_ai_budget_is_warning_only(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'UTC']);
        $run = $this->actingAs($admin)->postJson('/api/clients/'.$client->id.'/report-runs', ['frequency' => 'instant', 'preset' => 'last_7d'])
            ->assertAccepted()->json();
        $this->assertSame('2.0.0', $run['methodology_version']);
        Queue::assertPushed(GenerateReportSnapshotJob::class);

        $this->actingAs($admin)->putJson('/api/ai-governance/budgets', ['monthly_budget' => 100, 'warning_percent' => 70, 'critical_percent' => 90])->assertOk();
        $this->actingAs($admin)->getJson('/api/ai-governance/summary')->assertOk()->assertJsonPath('budget.monthly_budget', '100.0000');
    }

    public function test_notification_configuration_is_encrypted_and_never_returned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $response = $this->actingAs($admin)->postJson('/api/notification-destinations', [
            'client_id' => $client->id, 'name' => 'Ops email', 'channel' => 'email',
            'configuration' => ['address' => 'ops@example.com'], 'events' => ['alert', 'report_delivery'],
        ])->assertCreated()->assertJsonMissingPath('configuration')->assertJsonPath('configured', true);
        $this->assertNotSame('ops@example.com', \DB::table('notification_destinations')->where('id', $response->json('id'))->value('configuration'));
    }

    public function test_generic_webhooks_reject_private_network_targets(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $destination = NotificationDestination::create(['client_id' => $client->id, 'name' => 'Private hook', 'channel' => 'webhook', 'configuration' => ['url' => 'https://127.0.0.1/hook']]);
        $delivery = NotificationDelivery::create(['notification_destination_id' => $destination->id, 'event_type' => 'test', 'idempotency_key' => 'private-hook', 'payload' => ['text' => 'test']]);

        try {
            (new SendNotificationJob($delivery->id))->handle(app(SafePublicUrl::class));
            $this->fail('Private webhook should have been rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'failed']);
        }
    }
}
