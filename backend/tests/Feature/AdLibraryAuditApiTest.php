<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\AnalyzeAdLibraryCreativeJob;
use App\Jobs\CollectAdLibraryAuditContextJob;
use App\Models\AdLibraryAudit;
use App\Models\AdLibrarySearch;
use App\Models\AiModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdLibraryAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ad_library.enabled' => true,
            'ad_library_audit.enabled' => true,
            'ad_library_audit.providers.gemini.api_key' => 'test-key',
        ]);
    }

    public function test_feature_flag_hides_audit_endpoints(): void
    {
        config(['ad_library_audit.enabled' => false]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user)->getJson('/api/ad-library/audit-models')->assertNotFound();
    }

    public function test_model_catalog_filters_media_capabilities(): void
    {
        config(['ad_library_audit.providers.openai.api_key' => 'test-openai-key']);
        AiModel::create([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI GPT-4.1 mini',
            'capabilities' => ['text', 'vision', 'structured_json'], 'input_price' => 0.4, 'output_price' => 1.6,
            'enabled' => true, 'is_default' => false,
        ]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user)->getJson('/api/ad-library/audit-models?media_depth=full_media')
            ->assertOk()->assertJsonCount(1, 'models')->assertJsonPath('models.0.provider', 'gemini');
    }

    public function test_manager_creates_snapshot_of_grouped_creatives(): void
    {
        Queue::fake();
        [$manager, $run] = $this->runFor(UserRole::Marketer);
        $first = $run->results()->create(['library_ad_id' => 'ad-1', 'rank' => 1, 'body' => 'First', 'image_urls' => ['https://cdn.example/a.jpg'], 'raw_payload' => ['ad_id' => 'ad-1', 'collation_id' => 'same']]);
        $run->results()->create(['library_ad_id' => 'ad-2', 'rank' => 2, 'body' => 'First', 'image_urls' => ['https://cdn.example/a.jpg'], 'raw_payload' => ['ad_id' => 'ad-2', 'collation_id' => 'same']]);

        $response = $this->actingAs($manager)->postJson('/api/ad-library/audits', [
            'run_id' => $run->id, 'name' => 'Competitor audit',
            'media_depth' => 'visual', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'result_ids' => [$first->id],
        ])->assertAccepted();

        $audit = AdLibraryAudit::findOrFail($response->json('id'));
        $this->assertSame('Acme Running', $audit->company_name);
        $this->assertSame('competitor', $audit->company_type);
        $this->assertSame(['ad-1', 'ad-2'], $audit->creatives()->first()->library_ad_ids);
        Queue::assertPushed(CollectAdLibraryAuditContextJob::class, fn ($job) => $job->auditId === $audit->id);
        $run->delete();
        $this->assertDatabaseHas('ad_library_audits', ['id' => $audit->id, 'ad_library_run_id' => null]);
        $this->assertDatabaseHas('ad_library_audit_creatives', ['ad_library_audit_id' => $audit->id]);
    }

    public function test_viewer_can_read_and_export_but_cannot_mutate(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'lead', 'created_by' => $viewer->id, 'name' => 'Read only', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'context_review']);

        $this->actingAs($viewer)->getJson('/api/ad-library/audits/'.$audit->id)->assertOk();
        $this->get('/api/ad-library/audits/'.$audit->id.'/export.json')->assertOk();
        $this->putJson('/api/ad-library/audits/'.$audit->id.'/context', ['website_context' => []])->assertForbidden();
        $this->deleteJson('/api/ad-library/audits/'.$audit->id)->assertForbidden();
    }

    public function test_authenticated_viewer_can_read_shared_company_audits(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Other Company', 'company_type' => 'other', 'name' => 'Shared', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite']);
        $this->actingAs($viewer)->getJson('/api/ad-library/audits/'.$audit->id)->assertOk()
            ->assertJsonPath('company_name', 'Other Company');
    }

    public function test_persona_confirmation_analysis_and_creative_edit_invalidate_insights(): void
    {
        Bus::fake();
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'created_by' => $manager->id, 'name' => 'Audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'persona_review']);
        $persona = $audit->personas()->create(['name' => 'Busy parent', 'description' => 'Needs convenience', 'position' => 0]);
        $creative = $audit->creatives()->create(['creative_key' => 'one', 'library_ad_ids' => ['one'], 'source_snapshot' => ['library_ad_id' => 'one'], 'processing_status' => 'complete', 'angle' => 'Convenience']);

        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/personas/confirm')->assertOk();
        $this->postJson('/api/ad-library/audits/'.$audit->id.'/analyze')->assertAccepted();
        Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof AnalyzeAdLibraryCreativeJob));

        $audit->update(['status' => 'complete', 'insights' => ['executive_summary' => 'Old'], 'grid_confirmed_at' => now()]);
        $this->putJson('/api/ad-library/audits/'.$audit->id.'/creatives/'.$creative->id, [
            'angle' => 'Problem solution', 'primary_persona_id' => $persona->id, 'secondary_persona_ids' => [], 'reviewed' => true,
        ])->assertOk();
        $audit->refresh();
        $this->assertTrue($audit->insights_stale);
        $this->assertNull($audit->grid_confirmed_at);
    }

    private function runFor(UserRole $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $search = AdLibrarySearch::create(['created_by' => $user->id, 'company_name' => 'Acme Running', 'company_type' => 'competitor', 'website_url' => 'https://acme.example', 'name' => 'Search', 'search_mode' => 'keyword', 'query' => 'shoes']);
        $run = $search->runs()->create(['requested_by' => $user->id, 'status' => 'complete', 'result_count' => 2]);

        return [$user, $run];
    }
}
