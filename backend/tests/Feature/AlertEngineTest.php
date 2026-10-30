<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\AlertRule;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Services\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_failures_trigger_once_and_two_healthy_runs_recover(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'x'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        AlertRule::create(['client_id' => $client->id, 'name' => 'ROAS below target', 'metric' => 'roas', 'operator' => 'below', 'threshold' => 2, 'severity' => 'warning']);
        $snapshot = MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => now('Asia/Kolkata')->toDateString(), 'currency' => 'INR', 'spend' => 100, 'conversion_value' => 100, 'collected_at' => now()]);
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
        MetricSnapshot::create(['ad_account_id' => $account->id, 'scope' => 'account', 'scope_key' => 'account:'.$account->id, 'metric_date' => now('Asia/Kolkata')->toDateString(), 'currency' => 'INR', 'spend' => 250, 'conversions' => 0, 'collected_at' => now()]);
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
}
