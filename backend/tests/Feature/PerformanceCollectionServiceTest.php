<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Services\PerformanceCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerformanceCollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmapped_google_conversions_fall_back_to_the_client_objective(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'google-fallback', 'objective' => 'lead_gen', 'currency' => 'INR']);
        $connection = PlatformConnection::create([
            'platform' => 'google', 'name' => 'Google', 'scope' => 'agency',
            'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy',
        ]);
        $account = AdAccount::create([
            'client_id' => $client->id, 'platform_connection_id' => $connection->id,
            'platform' => 'google', 'external_id' => '123', 'name' => 'Acme Google',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata',
        ]);
        $method = new \ReflectionMethod(PerformanceCollectionService::class, 'googleFallbackMetrics');
        $metrics = $method->invoke(app(PerformanceCollectionService::class), $account, [
            'conversions' => 7, 'conversionsValue' => 1400,
        ]);

        $this->assertSame(7.0, $metrics['conversions']);
        $this->assertSame(7.0, $metrics['leads']);
        $this->assertSame(0.0, $metrics['purchases']);
    }

    public function test_meta_sales_results_use_one_primary_purchase_action_without_alias_double_counting(): void
    {
        $client = Client::create(['name' => 'GRS Fantasy Park', 'slug' => 'grs', 'objective' => 'lead_gen', 'currency' => 'INR']);
        $connection = PlatformConnection::create([
            'platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency',
            'credentials' => ['access_token' => 'test-token'], 'status' => 'healthy',
        ]);
        $account = AdAccount::create([
            'client_id' => $client->id, 'platform_connection_id' => $connection->id,
            'platform' => 'meta', 'external_id' => '123', 'name' => 'GRS Meta',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'campaign_id' => '120252514359850520',
            'campaign_name' => 'BS_Dasara_Scaling_13.10.2026',
            'objective' => 'OUTCOME_SALES',
            'spend' => '19200', 'impressions' => '10000', 'reach' => '8000', 'clicks' => '500',
            'date_start' => '2026-10-20', 'date_stop' => '2026-10-20',
            'actions' => [
                ['action_type' => 'web_in_store_purchase', 'value' => '99'],
                ['action_type' => 'web_app_in_store_purchase', 'value' => '99'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '96'],
                ['action_type' => 'purchase', 'value' => '96'],
                ['action_type' => 'omni_purchase', 'value' => '120'],
                ['action_type' => 'offline_conversion.purchase', 'value' => '24'],
                ['action_type' => 'lead', 'value' => '2'],
            ],
            'action_values' => [
                ['action_type' => 'web_in_store_purchase', 'value' => '495000'],
                ['action_type' => 'web_app_in_store_purchase', 'value' => '495000'],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '480000'],
                ['action_type' => 'purchase', 'value' => '480000'],
                ['action_type' => 'omni_purchase', 'value' => '600000'],
            ],
        ]]])]);

        app(PerformanceCollectionService::class)->collect($account, '2026-10-20', '2026-10-20');

        Http::assertSent(fn ($request) => ($request->data()['use_unified_attribution_setting'] ?? null) === true);
        $snapshot = MetricSnapshot::query()->where('scope', 'campaign')->firstOrFail();
        $this->assertSame('99.000000', $snapshot->purchases);
        $this->assertSame('99.000000', $snapshot->conversions);
        $this->assertSame('0.000000', $snapshot->leads);
        $this->assertSame('495000.000000', $snapshot->conversion_value);
        $this->assertSame('OUTCOME_SALES', $snapshot->campaign->type);
    }

    public function test_meta_hierarchy_collection_is_idempotent(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme-hierarchy', 'objective' => 'both', 'currency' => 'INR']);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency', 'credentials' => ['access_token' => 'test-token'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => '456', 'name' => 'Acme Meta', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        Http::fake(function ($request) {
            if (! ($request->data()['level'] ?? null)) {
                $path = parse_url($request->url(), PHP_URL_PATH);
                if (str_ends_with($path, '/adsets')) {
                    return Http::response(['data' => [['id' => 'g1', 'name' => 'Ad set', 'status' => 'PAUSED', 'optimization_goal' => 'LEAD_GENERATION']]]);
                }

                return Http::response(['data' => [['id' => 'a1', 'name' => 'Creative', 'status' => 'ACTIVE', 'adset_id' => 'g1']]]);
            }
            $level = $request->data()['level'];
            $row = ['campaign_id' => 'c1', 'campaign_name' => 'Campaign', 'objective' => 'OUTCOME_LEADS', 'spend' => '100', 'impressions' => '1000', 'reach' => '800', 'clicks' => '50', 'date_start' => '2026-10-20', 'date_stop' => '2026-10-20', 'actions' => [['action_type' => 'lead', 'value' => '5']]];
            if ($level === 'adset') {
                $row += ['adset_id' => 'g1', 'adset_name' => 'Ad set'];
            }
            if ($level === 'ad') {
                $row += ['adset_id' => 'g1', 'adset_name' => 'Ad set', 'ad_id' => 'a1', 'ad_name' => 'Creative'];
            }

            return Http::response(['data' => [$row]]);
        });

        $collector = app(PerformanceCollectionService::class);
        $collector->collect($account, '2026-10-20', '2026-10-20');
        $collector->collect($account->fresh(), '2026-10-20', '2026-10-20');

        $this->assertDatabaseCount('ad_groups', 1);
        $this->assertDatabaseCount('ads', 1);
        $this->assertDatabaseHas('ad_groups', ['external_id' => 'g1', 'status' => 'PAUSED']);
        $this->assertDatabaseHas('ads', ['external_id' => 'a1', 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('metric_snapshots', 4);
        $this->assertDatabaseHas('metric_snapshots', ['scope' => 'ad_group', 'leads' => 5]);
        $this->assertDatabaseHas('metric_snapshots', ['scope' => 'ad', 'leads' => 5]);
    }
}
