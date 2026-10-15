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
}
