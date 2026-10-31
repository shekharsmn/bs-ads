<?php

namespace Tests\Feature;

use App\Jobs\GeneratePerformancePulseJob;
use App\Jobs\SendNotificationJob;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\NotificationDestination;
use App\Models\PerformancePulse;
use App\Models\PerformancePulseRun;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\PerformancePulseRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformancePulseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_migration_can_resume_after_mysql_has_committed_earlier_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_31_000000_create_performance_pulses.php');

        $migration->up();

        $this->assertTrue(Schema::hasTable('performance_pulses'));
        $this->assertTrue(Schema::hasTable('performance_pulse_destination'));
        $this->assertTrue(Schema::hasTable('performance_pulse_runs'));
        $this->assertTrue(Schema::hasColumns('notification_deliveries', [
            'performance_pulse_run_id',
            'part_number',
            'part_count',
        ]));
    }

    public function test_manager_can_create_disabled_account_pulse_and_send_it_immediately(): void
    {
        Queue::fake();
        [$user, $client, $account] = $this->fixture('ecommerce');
        $destination = NotificationDestination::create(['client_id' => $client->id, 'name' => 'Morning chat', 'channel' => 'google_chat', 'configuration' => ['url' => 'https://chat.googleapis.com/v1/spaces/test/messages'], 'enabled' => true]);

        $response = $this->actingAs($user)->postJson('/api/performance-pulses', [
            'client_id' => $client->id, 'ad_account_id' => $account->id, 'campaign_id' => null,
            'periods' => ['yesterday', 'month_to_date'], 'local_time' => '09:00', 'include_campaign_breakdown' => true,
            'destination_ids' => [$destination->id],
        ])->assertCreated()->assertJsonPath('enabled', false)->assertJsonPath('destinations.0.id', $destination->id);

        $this->actingAs($user)->postJson('/api/performance-pulses/'.$response->json('id').'/send')->assertAccepted()->assertJsonPath('trigger', 'manual');
        Queue::assertPushed(GeneratePerformancePulseJob::class);
    }

    public function test_scope_and_destination_must_belong_to_the_same_client(): void
    {
        [$user, $client, $account] = $this->fixture();
        [, $otherClient] = $this->fixture('lead_gen', 'other');
        $destination = NotificationDestination::create(['client_id' => $otherClient->id, 'name' => 'Other', 'channel' => 'email', 'configuration' => ['address' => 'other@example.com']]);

        $this->actingAs($user)->postJson('/api/performance-pulses', [
            'client_id' => $client->id, 'ad_account_id' => $account->id, 'periods' => ['yesterday', 'last_7_days'],
            'local_time' => '09:00', 'destination_ids' => [$destination->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_ids');
    }

    public function test_mixed_account_renderer_splits_objectives_and_suppresses_zero_spend_sections(): void
    {
        [, $client, $account] = $this->fixture('both');
        $lead = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'lead', 'name' => 'Lead Search', 'type' => 'LEAD_GENERATION']);
        $zeroLead = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'lead-zero', 'name' => 'Social Marketing', 'type' => 'LEAD_GENERATION']);
        $sales = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'sales', 'name' => 'Shopping', 'type' => 'SHOPPING']);
        MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $lead->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$lead->id, 'metric_date' => '2026-10-29', 'currency' => 'INR', 'spend' => 667.14, 'leads' => 3, 'collected_at' => '2026-10-30 01:00:00']);
        MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $zeroLead->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$zeroLead->id, 'metric_date' => '2026-10-29', 'currency' => 'INR', 'spend' => 2037.69, 'leads' => 0, 'collected_at' => '2026-10-30 01:00:00']);
        MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $sales->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$sales->id, 'metric_date' => '2026-10-29', 'currency' => 'INR', 'spend' => 0, 'purchases' => 2, 'conversion_value' => 15996, 'collected_at' => '2026-10-30 01:00:00']);
        $pulse = PerformancePulse::create(['client_id' => $client->id, 'ad_account_id' => $account->id, 'scope_key' => 'account:'.$account->id, 'periods' => ['yesterday', 'last_7_days'], 'local_time' => '09:00', 'include_campaign_breakdown' => true]);

        $result = app(PerformancePulseRenderer::class)->render($pulse, CarbonImmutable::parse('2026-10-29', 'Asia/Kolkata'));

        $this->assertCount(1, $result['snapshot']['sections']);
        $this->assertSame('lead_gen', $result['snapshot']['sections'][0]['objective']);
        $this->assertStringContainsString('Lead Search', $result['texts'][0]);
        $this->assertStringContainsString('CPL: ₹222.38', $result['texts'][0]);
        $this->assertStringContainsString('Social Marketing', $result['texts'][0]);
        $this->assertStringContainsString('CPL: 0', $result['texts'][0]);
    }

    public function test_scheduler_creates_only_one_run_for_a_local_day(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-10-30 03:30:00 UTC');
        [, $client, $account] = $this->fixture();
        $destination = NotificationDestination::create(['client_id' => $client->id, 'name' => 'Chat', 'channel' => 'google_chat', 'configuration' => ['url' => 'https://chat.googleapis.com/v1/spaces/test/messages']]);
        $pulse = PerformancePulse::create(['client_id' => $client->id, 'ad_account_id' => $account->id, 'scope_key' => 'account:'.$account->id, 'periods' => ['yesterday', 'last_7_days'], 'local_time' => '09:00', 'enabled' => true]);
        $pulse->destinations()->attach($destination);

        $this->artisan('performance-pulses:dispatch-due')->assertSuccessful();
        $this->artisan('performance-pulses:dispatch-due')->assertSuccessful();

        $this->assertDatabaseCount('performance_pulse_runs', 1);
        $this->assertSame('2026-10-29', PerformancePulseRun::first()->reporting_date->toDateString());
        Queue::assertPushed(GeneratePerformancePulseJob::class, 1);
    }

    public function test_month_to_date_uses_the_month_containing_yesterday_on_the_first_day_of_a_month(): void
    {
        [, $client, $account] = $this->fixture('ecommerce');
        $campaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'sales', 'name' => 'Shopping', 'type' => 'SHOPPING']);
        foreach ([['2026-10-01', 100], ['2026-10-31', 250]] as [$date, $spend]) {
            MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $campaign->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$campaign->id, 'metric_date' => $date, 'currency' => 'INR', 'spend' => $spend, 'purchases' => 1, 'conversion_value' => 500, 'collected_at' => '2026-11-01 01:00:00']);
        }
        $pulse = PerformancePulse::create(['client_id' => $client->id, 'ad_account_id' => $account->id, 'scope_key' => 'account:'.$account->id, 'periods' => ['yesterday', 'month_to_date'], 'local_time' => '09:00']);

        $result = app(PerformancePulseRenderer::class)->render($pulse, CarbonImmutable::parse('2026-10-31', 'Asia/Kolkata'));
        $mtd = $result['snapshot']['sections'][0]['periods']['month_to_date'];

        $this->assertSame('2026-10-01', $mtd['start']);
        $this->assertSame('2026-10-31', $mtd['end']);
        $this->assertSame(350.0, $mtd['metrics']['spend']);
    }

    public function test_generation_fans_out_deliveries_and_delivery_completes_the_run(): void
    {
        Queue::fake();
        Http::fake(['https://chat.googleapis.com/*' => Http::response(['name' => 'spaces/test/messages/1'])]);
        [, $client, $account] = $this->fixture('ecommerce');
        $campaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'sales', 'name' => 'Shopping', 'type' => 'SHOPPING']);
        MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $campaign->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$campaign->id, 'metric_date' => '2026-10-29', 'currency' => 'INR', 'spend' => 100, 'purchases' => 2, 'conversion_value' => 500, 'collected_at' => '2026-10-30 01:00:00']);
        $destination = NotificationDestination::create(['client_id' => $client->id, 'name' => 'Chat', 'channel' => 'google_chat', 'configuration' => ['url' => 'https://chat.googleapis.com/v1/spaces/test/messages'], 'enabled' => true]);
        $pulse = PerformancePulse::create(['client_id' => $client->id, 'ad_account_id' => $account->id, 'scope_key' => 'account:'.$account->id, 'periods' => ['yesterday', 'month_to_date'], 'local_time' => '09:00']);
        $pulse->destinations()->attach($destination);
        $run = PerformancePulseRun::create(['performance_pulse_id' => $pulse->id, 'trigger' => 'manual', 'reporting_date' => '2026-10-29', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'pending']);

        (new GeneratePerformancePulseJob($run->id))->handle(app(PerformancePulseRenderer::class));
        $delivery = $run->deliveries()->firstOrFail();
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertStringContainsString('ROAS: 5.00', $delivery->payload['text']);
        Queue::assertPushed(SendNotificationJob::class, 1);

        (new SendNotificationJob($delivery->id))->handle(app(\App\Services\AdLibraryAudit\SafePublicUrl::class));
        $this->assertSame('delivered', $run->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    private function fixture(string $objective = 'lead_gen', string $slug = 'acme'): array
    {
        $user = User::factory()->create(['role' => 'marketer']);
        $client = Client::create(['name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'objective' => $objective]);
        $client->users()->attach($user);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'Google '.$slug, 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => $slug, 'name' => ucfirst($slug).' Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'status' => 'active']);

        return [$user, $client, $account];
    }
}
