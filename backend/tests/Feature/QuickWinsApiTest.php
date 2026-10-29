<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\AdAccount;
use App\Models\AlertRule;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuickWinsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_report_access_is_revoked_with_the_client_assignment(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $assigned = Client::create(['name' => 'Assigned', 'slug' => 'assigned']);
        $hidden = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $assigned->users()->attach($marketer);
        $visible = Report::create(['user_id' => $marketer->id, 'client_id' => $assigned->id, 'platform' => 'google', 'status' => 'complete']);
        $revoked = Report::create(['user_id' => $marketer->id, 'client_id' => $hidden->id, 'platform' => 'meta', 'status' => 'complete']);
        $legacy = Report::create(['user_id' => $marketer->id, 'platform' => 'google', 'status' => 'complete']);

        $response = $this->actingAs($marketer)->getJson('/api/reports?per_page=10')->assertOk()
            ->assertJsonPath('per_page', 10)
            ->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$visible->id, $legacy->id], collect($response->json('data'))->pluck('id')->all());
        $this->actingAs($marketer)->getJson('/api/reports/'.$revoked->id.'/status')->assertForbidden();
        $this->actingAs($marketer)->getJson('/api/reports/'.$legacy->id.'/status')->assertOk();
    }

    public function test_schedule_reassignment_requires_access_to_the_destination_client(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $source = Client::create(['name' => 'Source', 'slug' => 'source']);
        $destination = Client::create(['name' => 'Destination', 'slug' => 'destination']);
        $source->users()->attach($marketer);
        $schedule = ReportSchedule::create(['client_id' => $source->id, 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00']);

        $this->actingAs($marketer)->putJson('/api/report-schedules/'.$schedule->id, ['client_id' => $destination->id])->assertForbidden();
        $destination->users()->attach($marketer);
        $this->actingAs($marketer)->putJson('/api/report-schedules/'.$schedule->id, ['client_id' => $destination->id])->assertOk()->assertJsonPath('client_id', $destination->id);
    }

    public function test_performance_reports_support_client_and_account_scopes(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Scoped', 'slug' => 'scoped', 'timezone' => 'UTC']);
        $other = Client::create(['name' => 'Other', 'slug' => 'other', 'timezone' => 'UTC']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => 'scope-1', 'name' => 'Scoped Ads', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherAccount = AdAccount::create(['client_id' => $other->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => 'scope-2', 'name' => 'Other Ads', 'currency' => 'USD', 'timezone' => 'UTC']);

        $payload = ['client_id' => $client->id, 'ad_account_id' => $account->id, 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00'];
        $this->actingAs($admin)->postJson('/api/report-schedules', $payload)->assertCreated()
            ->assertJsonPath('scope_key', 'account:'.$account->id);
        $this->postJson('/api/report-schedules', array_merge($payload, ['local_time' => '10:00']))->assertCreated();
        $this->assertDatabaseCount('report_schedules', 1);

        $this->postJson('/api/report-schedules', array_merge($payload, ['ad_account_id' => $otherAccount->id]))
            ->assertUnprocessable()->assertJsonPath('message', 'The selected client, account, and campaign must belong to the same client.');

        $this->postJson('/api/clients/'.$client->id.'/report-runs', ['preset' => 'last_7d', 'ad_account_id' => $account->id])
            ->assertAccepted()->assertJsonPath('ad_account_id', $account->id);
        $run = ReportRun::firstOrFail();
        $this->assertSame($account->id, $run->ad_account_id);
        Queue::assertPushed(GenerateReportSnapshotJob::class, fn ($job) => $job->reportRunId === $run->id);

        $this->getJson('/api/report-schedules?client_id='.$client->id.'&ad_account_id='.$account->id)
            ->assertOk()->assertJsonCount(1, 'schedules')->assertJsonCount(1, 'runs');
    }

    public function test_alert_rule_rejects_cross_client_scope_and_authorizes_its_target(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $source = Client::create(['name' => 'Source', 'slug' => 'source']);
        $destination = Client::create(['name' => 'Destination', 'slug' => 'destination']);
        $source->users()->attach($marketer);
        $connection = PlatformConnection::create(['client_id' => $destination->id, 'platform' => 'meta', 'name' => 'Meta', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $destination->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => '1', 'name' => 'Destination Ads', 'currency' => 'USD', 'timezone' => 'UTC']);
        $rule = AlertRule::create(['client_id' => $source->id, 'name' => 'Spend', 'metric' => 'spend', 'operator' => 'above', 'threshold' => 100, 'severity' => 'warning']);

        $this->actingAs($marketer)->putJson('/api/alert-rules/'.$rule->id, ['ad_account_id' => $account->id])
            ->assertUnprocessable()->assertJsonPath('message', 'The selected client, account, and campaign must belong to the same client.');
        $this->actingAs($marketer)->putJson('/api/alert-rules/'.$rule->id, ['client_id' => null, 'ad_account_id' => $account->id])->assertForbidden();
    }

    public function test_public_reports_are_not_cacheable_and_are_rate_limited(): void
    {
        $owner = User::factory()->create();
        $report = Report::create(['user_id' => $owner->id, 'platform' => 'google', 'status' => 'complete', 'public_token' => str_repeat('a', 48)]);

        $this->getJson('/api/public/reports/'.$report->public_token)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        for ($attempt = 1; $attempt < 60; $attempt++) {
            $this->getJson('/api/public/reports/'.$report->public_token)->assertOk();
        }
        $this->getJson('/api/public/reports/'.$report->public_token)->assertTooManyRequests();
    }
}
