<?php

namespace Tests\Feature;

use App\Jobs\CollectAccountMetricsJob;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PerformanceHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('performance:command-center-refresh-cooldown');
    }

    protected function tearDown(): void
    {
        Cache::forget('performance:command-center-refresh-cooldown');
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_command_center_groups_accounts_by_client_and_platform_and_uses_the_higher_spend_result_type(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        [$user, $account] = $this->accountFixture('Asia/Kolkata');
        $leadCampaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'lead', 'name' => 'Lead campaign', 'type' => 'OUTCOME_LEADS']);
        $salesCampaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'sales', 'name' => 'Sales campaign', 'type' => 'OUTCOME_SALES']);
        foreach ([
            [$leadCampaign, '2026-10-27', 100, 5, 0, 5],
            [$salesCampaign, '2026-10-27', 200, 0, 2, 2],
            [$salesCampaign, '2026-10-26', 60, 0, 3, 3],
            [$salesCampaign, '2026-09-27', 999, 0, 99, 99],
        ] as [$campaign, $date, $spend, $leads, $purchases, $conversions]) {
            MetricSnapshot::create([
                'ad_account_id' => $account->id, 'campaign_id' => $campaign->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$campaign->id,
                'metric_date' => $date, 'currency' => 'INR', 'spend' => $spend, 'leads' => $leads,
                'purchases' => $purchases, 'conversions' => $conversions, 'collected_at' => now(),
            ]);
        }
        $secondGoogle = AdAccount::create([
            'client_id' => $account->client_id, 'platform_connection_id' => $account->platform_connection_id,
            'platform' => 'google', 'external_id' => 'acme-2', 'name' => 'Acme Ads 2', 'currency' => 'INR',
            'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ]);
        $secondSales = Campaign::create(['ad_account_id' => $secondGoogle->id, 'external_id' => 'sales-2', 'name' => 'Sales 2', 'type' => 'SHOPPING']);
        MetricSnapshot::create([
            'ad_account_id' => $secondGoogle->id, 'campaign_id' => $secondSales->id, 'scope' => 'campaign',
            'scope_key' => 'campaign:'.$secondSales->id, 'metric_date' => '2026-10-27', 'currency' => 'INR',
            'spend' => 40, 'purchases' => 1, 'conversions' => 1, 'collected_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/performance/accounts')->assertOk();
        $response->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.account_count', 2)
            ->assertJsonPath('accounts.0.name', 'Acme Ads')
            ->assertJsonPath('accounts.0.external_id', 'acme')
            ->assertJsonPath('accounts.0.timezone', 'Asia/Kolkata')
            ->assertJsonPath('accounts.0.result_type', 'sales')
            ->assertJsonPath('accounts.0.windows.yesterday.spend', 240)
            ->assertJsonPath('accounts.0.windows.yesterday.results', 3)
            ->assertJsonPath('accounts.0.windows.yesterday.cpr', 80)
            ->assertJsonPath('accounts.0.windows.last_7_days.spend', 300)
            ->assertJsonPath('accounts.0.windows.last_7_days.results', 6)
            ->assertJsonPath('accounts.0.windows.last_30_days.spend', 300);
    }

    public function test_command_center_uses_historical_google_conversions_when_canonical_results_are_empty(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        [$user, $account] = $this->accountFixture('Asia/Kolkata');
        MetricSnapshot::create([
            'ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id,
            'metric_date' => '2026-10-27', 'currency' => 'INR', 'spend' => 250, 'conversions' => 10,
            'leads' => 0, 'purchases' => 0, 'collected_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/performance/accounts')->assertOk()
            ->assertJsonPath('accounts.0.result_type', 'leads')
            ->assertJsonPath('accounts.0.windows.yesterday.results', 10)
            ->assertJsonPath('accounts.0.windows.yesterday.cpr', 25);
    }

    public function test_command_center_refresh_forces_collection_for_accessible_active_accounts(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 10:00:00');
        Queue::fake();
        [$viewer, $account] = $this->accountFixture();
        [, $otherAccount] = $this->accountFixture('UTC', 'other');

        $this->actingAs($viewer)->postJson('/api/performance/refresh')->assertStatus(202)
            ->assertJsonPath('queued_accounts', 1)
            ->assertJsonPath('refresh.available', false)
            ->assertJsonPath('refresh.available_at', '2026-10-29T10:05:00+00:00')
            ->assertJsonPath('refresh.remaining_seconds', 300)
            ->assertJsonStructure(['requested_at']);

        Queue::assertPushed(CollectAccountMetricsJob::class, 1);
        Queue::assertPushed(CollectAccountMetricsJob::class, fn (CollectAccountMetricsJob $job) => $job->adAccountId === $account->id && filled($job->refreshToken));
        Queue::assertNotPushed(CollectAccountMetricsJob::class, fn (CollectAccountMetricsJob $job) => $job->adAccountId === $otherAccount->id);
    }

    public function test_command_center_refresh_cooldown_is_global_reload_safe_and_expires_after_five_minutes(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 10:00:00');
        Queue::fake();
        [$firstViewer] = $this->accountFixture();
        [$secondViewer] = $this->accountFixture('UTC', 'other');

        $this->actingAs($firstViewer)->postJson('/api/performance/refresh')->assertAccepted();

        $this->actingAs($secondViewer)->getJson('/api/performance/accounts')->assertOk()
            ->assertJsonPath('refresh.available', false)
            ->assertJsonPath('refresh.available_at', '2026-10-29T10:05:00+00:00')
            ->assertJsonPath('refresh.remaining_seconds', 300);

        $this->actingAs($secondViewer)->postJson('/api/performance/refresh')->assertStatus(429)
            ->assertHeader('Retry-After', '300')
            ->assertJsonPath('refresh.available', false)
            ->assertJsonPath('refresh.remaining_seconds', 300);
        Queue::assertPushed(CollectAccountMetricsJob::class, 1);

        CarbonImmutable::setTestNow('2026-10-29 10:05:01');
        $this->actingAs($secondViewer)->getJson('/api/performance/accounts')->assertOk()
            ->assertJsonPath('refresh.available', true)
            ->assertJsonPath('refresh.available_at', null)
            ->assertJsonPath('refresh.remaining_seconds', 0);
        $this->actingAs($secondViewer)->postJson('/api/performance/refresh')->assertAccepted();
        Queue::assertPushed(CollectAccountMetricsJob::class, 2);
    }

    public function test_nested_hierarchy_is_access_checked_and_parent_checked(): void
    {
        [$viewer, $account] = $this->accountFixture();
        $campaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'c1', 'name' => 'Campaign']);
        $group = AdGroup::create(['campaign_id' => $campaign->id, 'external_id' => 'g1', 'name' => 'Ad group', 'status' => 'ENABLED']);
        $ad = Ad::create(['ad_group_id' => $group->id, 'external_id' => 'a1', 'name' => 'Ad', 'status' => 'ENABLED']);
        foreach ([['campaign', 'campaign:'.$campaign->id, $campaign->id, null, null], ['ad_group', 'ad_group:'.$group->id, $campaign->id, $group->id, null], ['ad', 'ad:'.$ad->id, $campaign->id, $group->id, $ad->id]] as [$scope, $key, $campaignId, $groupId, $adId]) {
            MetricSnapshot::create(['ad_account_id' => $account->id, 'campaign_id' => $campaignId, 'ad_group_id' => $groupId, 'ad_id' => $adId, 'scope' => $scope, 'scope_key' => $key, 'metric_date' => '2026-10-20', 'currency' => 'INR', 'spend' => 100, 'leads' => 4, 'collected_at' => now()]);
        }
        $dates = '?start_date=2026-10-01&end_date=2026-10-27';
        $this->actingAs($viewer)->getJson("/api/performance/accounts/{$account->id}/campaigns/{$campaign->id}/ad-groups{$dates}")
            ->assertOk()->assertJsonPath('ad_groups.0.ad_group.name', 'Ad group')->assertJsonPath('metrics.cpl', 25);
        $this->actingAs($viewer)->getJson("/api/performance/accounts/{$account->id}/campaigns/{$campaign->id}/ad-groups/{$group->id}/ads{$dates}")
            ->assertOk()->assertJsonPath('ads.0.ad.name', 'Ad');

        [, $otherAccount] = $this->accountFixture('UTC', 'other');
        $this->actingAs($viewer)->getJson("/api/performance/accounts/{$otherAccount->id}/campaigns/{$campaign->id}/ad-groups{$dates}")->assertForbidden();
    }

    private function accountFixture(string $timezone = 'Asia/Kolkata', string $slug = 'acme'): array
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'INR']);
        $client->users()->attach($user);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC '.$slug, 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => $slug, 'name' => ucfirst($slug).' Ads', 'currency' => 'INR', 'timezone' => $timezone, 'status' => 'active']);

        return [$user, $account];
    }
}
