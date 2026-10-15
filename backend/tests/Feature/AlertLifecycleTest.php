<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledge_note_snooze_and_resolve_are_audited(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $client->users()->attach($marketer);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'x'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => '1', 'name' => 'Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata']);
        $rule = AlertRule::create(['client_id' => $client->id, 'name' => 'CPL high', 'metric' => 'cpl', 'operator' => 'above', 'threshold' => 100, 'severity' => 'warning']);
        $incident = AlertIncident::create(['client_id' => $client->id, 'alert_rule_id' => $rule->id, 'ad_account_id' => $account->id, 'incident_key' => 'unique', 'status' => 'open', 'severity' => 'warning', 'title' => 'CPL high', 'triggered_at' => now()]);

        $this->actingAs($marketer)->postJson("/api/alerts/{$incident->id}/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->actingAs($marketer)->postJson("/api/alerts/{$incident->id}/note", ['note' => 'Investigating search terms.'])->assertOk();
        $this->actingAs($marketer)->postJson("/api/alerts/{$incident->id}/snooze", ['snoozed_until' => now()->addHours(6)->toIso8601String()])->assertOk()->assertJsonPath('status', 'snoozed');
        $this->actingAs($marketer)->postJson("/api/alerts/{$incident->id}/resolve", ['note' => 'Fixed'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertDatabaseCount('alert_events', 4);
    }
}
