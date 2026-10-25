<?php

namespace Tests\Feature;

use App\Models\AdLibraryResult;
use App\Models\AdLibraryRun;
use App\Models\AdLibrarySearch;
use App\Models\AlertIncident;
use App\Models\Client;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoreUxApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_center_limits_client_workflows_but_keeps_company_research_shared(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $assigned = Client::create(['name' => 'Assigned', 'slug' => 'assigned']);
        $hidden = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $assigned->users()->attach($viewer);
        Report::create(['user_id' => $viewer->id, 'client_id' => $assigned->id, 'platform' => 'google', 'status' => 'complete']);
        Report::create(['user_id' => $viewer->id, 'client_id' => $hidden->id, 'platform' => 'meta', 'status' => 'complete']);
        $search = AdLibrarySearch::create(['created_by' => $viewer->id, 'company_name' => 'Shared company', 'company_type' => 'competitor', 'name' => 'Shared search', 'search_mode' => 'keyword', 'query' => 'shared']);
        AdLibraryRun::create(['ad_library_search_id' => $search->id, 'requested_by' => $viewer->id, 'provider' => 'scraper', 'status' => 'complete']);

        $response = $this->actingAs($viewer)->getJson('/api/activities')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEqualsCanonicalizing(['Google account audit', 'Shared search'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_alerts_support_filtering_and_authorized_bulk_triage(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $client->users()->attach($marketer);
        $critical = $this->incident($client, 'critical', 'Critical pacing');
        $this->incident($client, 'warning', 'Warning pacing');

        $this->actingAs($marketer)->getJson('/api/alerts?severity=critical&q=Critical')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($marketer)->postJson('/api/alerts/bulk-actions', ['ids' => [$critical->id], 'action' => 'acknowledge'])
            ->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('alert_incidents', ['id' => $critical->id, 'status' => 'acknowledged']);
        $this->assertDatabaseHas('alert_events', ['alert_incident_id' => $critical->id, 'type' => 'acknowledge']);
    }

    public function test_shared_tags_personal_favorites_and_report_templates_are_persisted(): void
    {
        config(['services.ad_library.enabled' => true]);
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $search = AdLibrarySearch::create(['created_by' => $admin->id, 'company_name' => 'Rival', 'company_type' => 'competitor', 'name' => 'Rival search', 'search_mode' => 'keyword', 'query' => 'rival']);
        $run = AdLibraryRun::create(['ad_library_search_id' => $search->id, 'requested_by' => $admin->id, 'provider' => 'scraper', 'status' => 'complete']);
        $result = AdLibraryResult::create(['ad_library_run_id' => $run->id, 'library_ad_id' => '123', 'advertiser_name' => 'Rival', 'body' => 'Creative', 'raw_payload' => []]);

        $tag = $this->actingAs($admin)->postJson('/api/ad-library/tags', ['name' => 'Seasonal'])->assertCreated()->json();
        $this->actingAs($admin)->putJson('/api/ad-library/searches/'.$search->id.'/tags', ['tag_ids' => [$tag['id']]])->assertOk()->assertJsonPath('tags.0.name', 'Seasonal');
        $this->actingAs($admin)->putJson('/api/ad-library/results/'.$result->id.'/favorite', ['favorite' => true])->assertOk()->assertJsonPath('favorite', true);
        $this->actingAs($admin)->getJson('/api/ad-library/runs/'.$run->id.'/ads?favorites=1')->assertOk()->assertJsonPath('data.0.favorite', true);

        $template = $this->actingAs($admin)->postJson('/api/report-templates', ['client_id' => $client->id, 'name' => 'Client update', 'sections' => ['summary', 'alerts'], 'brand_name' => 'Acme Reports', 'brand_color' => '#123456'])->assertCreated()->json();
        $this->actingAs($admin)->postJson('/api/clients/'.$client->id.'/report-runs', ['frequency' => 'instant', 'preset' => 'last_7d', 'report_template_id' => $template['id']])->assertAccepted();
        $this->assertDatabaseHas('report_runs', ['client_id' => $client->id, 'report_template_id' => $template['id'], 'status' => 'pending']);
    }

    private function incident(Client $client, string $severity, string $title): AlertIncident
    {
        return AlertIncident::create([
            'client_id' => $client->id, 'incident_key' => hash('sha256', Str::uuid()->toString()),
            'status' => 'open', 'severity' => $severity, 'title' => $title, 'triggered_at' => now(),
        ]);
    }
}
