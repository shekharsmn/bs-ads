<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\AlertRule;
use App\Models\AlertRuleState;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Services\AlertEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_two_failures_trigger_once_and_two_healthy_runs_recover(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'x'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        AlertRule::create(['client_id' => $client->id, 'name' => 'ROAS below target', 'metric' => 'roas', 'operator' => 'below', 'threshold' => 2, 'severity' => 'warning']);
        $snapshot = MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => now('Asia/Kolkata')->subDay()->toDateString(), 'currency' => 'INR', 'spend' => 100, 'conversion_value' => 100, 'collected_at' => now()]);
        $engine = app(AlertEngine::class);

        $engine->evaluateAccount($account->fresh(['connection']));
        $this->assertDatabaseCount('alert_incidents', 0);
        $engine->evaluateAccount($account->fresh(['connection']));
        $this->assertDatabaseCount('alert_incidents', 1);
        $engine->evaluateAccount($account->fresh(['connection']));
        $this->assertDatabaseCount('alert_incidents', 1);

        $snapshot->update(['conversion_value' => 400]);
        $engine->evaluateAccount($account->fresh(['connection']));
        $this->assertDatabaseHas('alert_incidents', ['status' => 'open']);
        $engine->evaluateAccount($account->fresh(['connection']));
        $this->assertDatabaseHas('alert_incidents', ['status' => 'resolved']);
    }

    public function test_incident_records_spend_and_evaluation_windows(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme-alert-context']);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency', 'credentials' => ['access_token' => 'x'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => '2', 'name' => 'Meta Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        AlertRule::create(['client_id' => $client->id, 'name' => 'Spend without conversions', 'metric' => 'conversions', 'operator' => 'no_conversions_with_spend', 'severity' => 'critical', 'window_days' => 1, 'minimum_spend' => 100]);
        MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => now('Asia/Kolkata')->subDay()->toDateString(), 'currency' => 'INR', 'spend' => 250, 'conversions' => 0, 'collected_at' => now()]);
        $engine = app(AlertEngine::class);

        $engine->evaluateAccount($account->fresh(['connection']));
        $engine->evaluateAccount($account->fresh(['connection']));

        $incident = \App\Models\AlertIncident::firstOrFail();
        $this->assertSame(250.0, (float) $incident->observed_spend);
        $this->assertSame(0.0, (float) $incident->baseline_spend);
        $this->assertSame('INR', $incident->currency);
        $this->assertNotNull($incident->observed_period_start);
        $this->assertNotNull($incident->observed_period_end);
        $this->assertNotNull($incident->baseline_period_start);
        $this->assertNotNull($incident->baseline_period_end);
    }

    public function test_evaluation_uses_only_completed_local_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 17:30:00', 'Asia/Kolkata'));
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme-completed-days']);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta', 'scope' => 'agency', 'credentials' => ['access_token' => 'x'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => '3', 'name' => 'Meta Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        $rule = AlertRule::create(['client_id' => $client->id, 'name' => 'Daily CPL increased', 'metric' => 'cpl', 'operator' => 'percentage_increase', 'threshold' => 25, 'severity' => 'warning', 'window_days' => 1]);

        foreach ([
            ['date' => '2026-10-28', 'spend' => 100, 'leads' => 10],
            ['date' => '2026-10-29', 'spend' => 200, 'leads' => 10],
            ['date' => '2026-10-30', 'spend' => 1000, 'leads' => 1],
        ] as $metrics) {
            MetricSnapshot::create([
                'ad_account_id' => $account->id,
                'scope' => 'account',
                'scope_key' => 'account:'.$account->id,
                'metric_date' => $metrics['date'],
                'currency' => 'INR',
                'spend' => $metrics['spend'],
                'leads' => $metrics['leads'],
                'collected_at' => now(),
            ]);
        }

        app(AlertEngine::class)->evaluateAccount($account->fresh(['connection']));

        $result = AlertRuleState::where('alert_rule_id', $rule->id)->firstOrFail()->last_result;
        $this->assertTrue($result['breached']);
        $this->assertSame(20.0, (float) $result['observed']);
        $this->assertSame(10.0, (float) $result['baseline']);
        $this->assertSame('2026-10-28T18:30:00+00:00', $result['observed_period_start']);
        $this->assertSame('2026-10-29T18:29:59+00:00', $result['observed_period_end']);
        $this->assertSame('2026-10-27T18:30:00+00:00', $result['baseline_period_start']);
        $this->assertSame('2026-10-28T18:29:59+00:00', $result['baseline_period_end']);
    }
}
