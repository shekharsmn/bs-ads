<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\AnalyzeAdLibraryCreativeJob;
use App\Jobs\CollectAdLibraryAuditContextJob;
use App\Models\AdLibraryAudit;
use App\Models\AdLibrarySearch;
use App\Models\AiModel;
use App\Models\User;
use App\Services\AdLibraryAudit\AdLibraryAuditWorkflow;
use App\Services\AdLibraryAudit\WebsiteContextCrawler;
use App\Services\AdLibraryAudit\WebsiteContextFetchException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_audit_list_filters_and_detail_summary_support_the_operator_workspace(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $matching = AdLibraryAudit::create(['company_name' => 'Northstar Labs', 'company_type' => 'competitor', 'created_by' => $manager->id, 'name' => 'Northstar creative review', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'creative_review']);
        AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'client', 'created_by' => $manager->id, 'name' => 'Acme context', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'context_review']);
        $matching->creatives()->create(['creative_key' => 'reviewed', 'library_ad_ids' => ['reviewed'], 'source_snapshot' => [], 'processing_status' => 'complete', 'review_status' => 'reviewed']);
        $matching->creatives()->create(['creative_key' => 'pending-review', 'library_ad_ids' => ['pending-review'], 'source_snapshot' => [], 'processing_status' => 'complete', 'review_status' => 'pending']);
        $matching->creatives()->create(['creative_key' => 'failed', 'library_ad_ids' => ['failed'], 'source_snapshot' => [], 'processing_status' => 'failed']);
        $matching->creatives()->create(['creative_key' => 'skipped', 'library_ad_ids' => ['skipped'], 'source_snapshot' => [], 'processing_status' => 'skipped']);

        $this->actingAs($manager)->getJson('/api/ad-library/audits?q=Northstar&status=creative_review&company_type=competitor')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);

        $this->getJson('/api/ad-library/audits/'.$matching->id)->assertOk()
            ->assertJsonPath('creative_summary.total', 4)
            ->assertJsonPath('creative_summary.selected', 3)
            ->assertJsonPath('creative_summary.complete', 2)
            ->assertJsonPath('creative_summary.failed', 1)
            ->assertJsonPath('creative_summary.skipped', 1)
            ->assertJsonPath('creative_summary.reviewed', 1)
            ->assertJsonPath('creative_summary.pending_review', 1);
    }

    public function test_manager_marks_every_completed_creative_reviewed(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Northstar Labs', 'company_type' => 'competitor', 'created_by' => $manager->id, 'name' => 'Bulk review', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'creative_review']);
        $pending = $audit->creatives()->create(['creative_key' => 'pending', 'library_ad_ids' => ['pending'], 'source_snapshot' => [], 'processing_status' => 'complete', 'review_status' => 'pending']);
        $reviewed = $audit->creatives()->create(['creative_key' => 'reviewed', 'library_ad_ids' => ['reviewed'], 'source_snapshot' => [], 'processing_status' => 'complete', 'review_status' => 'reviewed']);
        $failed = $audit->creatives()->create(['creative_key' => 'failed', 'library_ad_ids' => ['failed'], 'source_snapshot' => [], 'processing_status' => 'failed', 'review_status' => 'pending']);

        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/creatives/review-all')
            ->assertOk()
            ->assertJsonPath('marked_reviewed', 1)
            ->assertJsonPath('creative_summary.reviewed', 2)
            ->assertJsonPath('creative_summary.pending_review', 0);

        $this->assertSame('reviewed', $pending->fresh()->review_status);
        $this->assertSame('reviewed', $reviewed->fresh()->review_status);
        $this->assertSame('pending', $failed->fresh()->review_status);

        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->actingAs($viewer)->postJson('/api/ad-library/audits/'.$audit->id.'/creatives/review-all')->assertForbidden();
    }

    public function test_creative_audit_exports_csv_excel_and_google_sheets_data(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Northstar Labs', 'company_type' => 'competitor', 'created_by' => $viewer->id, 'name' => 'Export audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'creative_review']);
        $persona = $audit->personas()->create(['name' => 'Busy parent', 'description' => 'Needs convenience', 'position' => 0]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'exported',
            'library_ad_ids' => ['ad-exported'],
            'source_snapshot' => ['title' => 'Sixty-second demo', 'media_type' => 'video'],
            'processing_status' => 'complete',
            'review_status' => 'reviewed',
            'product' => '=unsafe formula',
            'angle' => 'Fast reassurance',
            'confidence' => 0.86,
        ]);
        $creative->personas()->attach($persona->id, ['role' => 'primary']);

        $csv = $this->actingAs($viewer)->get('/api/ad-library/audits/'.$audit->id.'/export.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvContent = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent);
        $this->assertStringContainsString("'=unsafe formula", $csvContent);

        $sheets = $this->get('/api/ad-library/audits/'.$audit->id.'/export.sheets')
            ->assertOk()
            ->assertHeader('content-type', 'text/tab-separated-values; charset=UTF-8');
        $this->assertStringContainsString("Creative\tLink\tMedia", $sheets->getContent());
        $this->assertStringContainsString("'=unsafe formula", $sheets->getContent());

        $xlsx = $this->get('/api/ad-library/audits/'.$audit->id.'/export.xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $this->assertSame('PK', substr(file_get_contents($path), 0, 2));
        $archive = new \PharData($path);
        $worksheet = $archive['xl/worksheets/sheet1.xml']->getContent();
        $this->assertStringContainsString('Sixty-second demo', $worksheet);
        $this->assertStringContainsString('Busy parent', $worksheet);
        unset($archive);
        unlink($path);
    }

    public function test_persona_confirmation_analysis_and_creative_edit_invalidate_insights(): void
    {
        Bus::fake();
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'created_by' => $manager->id, 'name' => 'Audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'persona_review']);
        $persona = $audit->personas()->create(['name' => 'Busy parent', 'description' => 'Needs convenience', 'position' => 0]);
        $creative = $audit->creatives()->create(['creative_key' => 'one', 'library_ad_ids' => ['one'], 'source_snapshot' => ['library_ad_id' => 'one'], 'processing_status' => 'complete', 'angle' => 'Convenience']);

        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/personas/confirm')->assertOk()->assertJsonPath('status', 'creative_selection');
        $this->postJson('/api/ad-library/audits/'.$audit->id.'/analyze', ['creative_ids' => [$creative->id]])->assertAccepted();
        Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof AnalyzeAdLibraryCreativeJob));

        $audit->update(['status' => 'complete', 'insights' => ['executive_summary' => 'Old'], 'grid_confirmed_at' => now()]);
        $this->putJson('/api/ad-library/audits/'.$audit->id.'/creatives/'.$creative->id, [
            'angle' => 'Problem solution', 'primary_persona_id' => $persona->id, 'secondary_persona_ids' => [], 'reviewed' => true,
        ])->assertOk();
        $audit->refresh();
        $this->assertTrue($audit->insights_stale);
        $this->assertNull($audit->grid_confirmed_at);
    }

    public function test_manager_selects_creatives_and_can_pause_and_resume_analysis(): void
    {
        Bus::fake();
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'created_by' => $manager->id, 'name' => 'Selectable audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'creative_selection', 'personas_confirmed_at' => now()]);
        $selected = $audit->creatives()->create(['creative_key' => 'selected', 'library_ad_ids' => ['selected'], 'source_snapshot' => ['library_ad_id' => 'selected']]);
        $skipped = $audit->creatives()->create(['creative_key' => 'skipped', 'library_ad_ids' => ['skipped'], 'source_snapshot' => ['library_ad_id' => 'skipped']]);

        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/analyze', ['creative_ids' => [$selected->id]])
            ->assertAccepted()->assertJsonPath('status', 'analyzing_creatives')->assertJsonPath('progress_total', 1);
        $this->assertSame('pending', $selected->fresh()->processing_status);
        $this->assertSame('skipped', $skipped->fresh()->processing_status);
        $this->getJson('/api/ad-library/audits/'.$audit->id.'/creatives?per_page=100')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $selected->id);
        $this->getJson('/api/ad-library/audits/'.$audit->id.'/creatives?status=skipped&per_page=100')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $skipped->id);

        $this->postJson('/api/ad-library/audits/'.$audit->id.'/pause')->assertOk()->assertJsonPath('status', 'creative_paused');
        $this->postJson('/api/ad-library/audits/'.$audit->id.'/resume')->assertAccepted()->assertJsonPath('status', 'analyzing_creatives');
        Bus::assertBatched(fn ($batch) => $batch->name === 'Resume audit '.$audit->id && $batch->jobs->count() === 1);
    }

    public function test_paused_creative_job_remains_pending_without_calling_ai(): void
    {
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'name' => 'Paused audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'creative_paused']);
        $creative = $audit->creatives()->create(['creative_key' => 'paused', 'library_ad_ids' => ['paused'], 'source_snapshot' => ['library_ad_id' => 'paused'], 'processing_status' => 'pending']);
        $workflow = $this->mock(AdLibraryAuditWorkflow::class);
        $workflow->shouldNotReceive('analyzeCreative');

        (new AnalyzeAdLibraryCreativeJob($creative->id))->handle($workflow);

        $this->assertSame('pending', $creative->fresh()->processing_status);
    }

    public function test_creative_job_progress_query_removes_relationship_ordering(): void
    {
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'name' => 'Progress audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'analyzing_creatives']);
        $creative = $audit->creatives()->create(['creative_key' => 'progress', 'library_ad_ids' => ['progress'], 'source_snapshot' => ['library_ad_id' => 'progress'], 'processing_status' => 'pending']);
        $workflow = $this->mock(AdLibraryAuditWorkflow::class);
        $workflow->shouldReceive('analyzeCreative')->once()->andReturnUsing(fn ($row) => $row->update(['processing_status' => 'complete', 'raw_ai_response' => ['angle' => 'Complete']]));
        $aggregateQueries = [];
        DB::listen(function ($query) use (&$aggregateQueries) {
            if (str_contains($query->sql, 'SUM(CASE WHEN processing_status')) {
                $aggregateQueries[] = strtolower($query->sql);
            }
        });

        (new AnalyzeAdLibraryCreativeJob($creative->id))->handle($workflow);

        $audit->refresh();
        $this->assertSame('creative_review', $audit->status);
        $this->assertSame(1, $audit->progress_completed);
        $this->assertNotEmpty($aggregateQueries);
        $this->assertStringNotContainsString('order by', $aggregateQueries[0]);
    }

    public function test_failed_callback_preserves_a_completed_ai_result(): void
    {
        $audit = AdLibraryAudit::create(['company_name' => 'Acme Running', 'company_type' => 'partner', 'name' => 'Completed audit', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'analyzing_creatives']);
        $creative = $audit->creatives()->create(['creative_key' => 'complete', 'library_ad_ids' => ['complete'], 'source_snapshot' => ['library_ad_id' => 'complete'], 'processing_status' => 'complete', 'raw_ai_response' => ['angle' => 'Complete']]);

        (new AnalyzeAdLibraryCreativeJob($creative->id))->failed(new \RuntimeException('Progress bookkeeping failed.'));

        $this->assertSame('complete', $creative->fresh()->processing_status);
        $this->assertNull($creative->error_message);
    }

    public function test_failed_website_crawl_uses_gemini_url_context(): void
    {
        $this->mock(WebsiteContextCrawler::class, function ($mock) {
            $mock->shouldReceive('assertSafe')->once()->with('https://brand.test/');
            $mock->shouldReceive('crawl')->once()->with('https://brand.test/')->andThrow(
                new WebsiteContextFetchException('rate limited', 429)
            );
        });
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'summary' => 'A skincare brand.',
                    'facts' => ['Public fact'],
                    'products' => ['Face oil'],
                    'claims' => [],
                    'audience_signals' => ['Skincare customers'],
                    'positioning' => ['Simple skincare'],
                    'value_propositions' => ['Daily support'],
                    'evidence' => [['fact' => 'Public fact', 'url' => 'https://brand.test/']],
                ])]]],
                'urlContextMetadata' => ['urlMetadata' => [[
                    'retrievedUrl' => 'https://brand.test/',
                    'urlRetrievalStatus' => 'URL_RETRIEVAL_STATUS_SUCCESS',
                ]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10],
        ])]);
        $audit = AdLibraryAudit::create([
            'company_name' => 'Brand',
            'company_type' => 'competitor',
            'name' => 'URL context audit',
            'website_url' => 'https://brand.test/',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'model_key' => 'gemini:gemini-2.5-flash-lite',
            'prompt_version' => 1,
            'schema_version' => 1,
            'status' => 'collecting_context',
        ]);

        app(AdLibraryAuditWorkflow::class)->collectContext($audit);

        $audit->refresh();
        $this->assertSame('context_review', $audit->status);
        $this->assertSame('A skincare brand.', $audit->website_context['summary']);
        $this->assertSame('https://brand.test/', $audit->context_sources[0]['url']);
        $this->assertDatabaseHas('ad_library_audit_ai_requests', [
            'ad_library_audit_id' => $audit->id,
            'stage' => 'context',
            'status' => 'complete',
        ]);
    }

    public function test_unsafe_website_url_is_never_sent_to_an_ai_provider(): void
    {
        $this->mock(WebsiteContextCrawler::class, function ($mock) {
            $mock->shouldReceive('assertSafe')->once()->with('http://127.0.0.1/private')->andThrow(
                new \InvalidArgumentException('Private hosts are not allowed.')
            );
            $mock->shouldNotReceive('crawl');
        });
        Http::fake();

        $audit = AdLibraryAudit::create([
            'company_name' => 'Unsafe URL',
            'company_type' => 'competitor',
            'name' => 'Unsafe URL audit',
            'website_url' => 'http://127.0.0.1/private',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'model_key' => 'gemini:gemini-2.5-flash-lite',
            'prompt_version' => 1,
            'schema_version' => 1,
            'status' => 'collecting_context',
        ]);

        app(AdLibraryAuditWorkflow::class)->collectContext($audit);

        $audit->refresh();
        $this->assertSame('context_review', $audit->status);
        $this->assertSame([], $audit->context_sources);
        $this->assertStringContainsString('not a permitted public URL', $audit->error_message);
        $this->assertDatabaseCount('ad_library_audit_ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_creative_analysis_uses_full_personas_and_website_context_to_fill_review(): void
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Health Brand',
            'company_type' => 'competitor',
            'name' => 'Creative review audit',
            'website_context' => ['summary' => 'At-home wellness monitoring', 'products' => ['Home health monitor'], 'value_propositions' => ['Results in sixty seconds']],
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'text_only',
            'prompt_version' => 1,
            'schema_version' => 1,
            'status' => 'analyzing_creatives',
        ]);
        $persona = $audit->personas()->create([
            'name' => 'Health-conscious parent',
            'description' => 'Wants confidence between appointments',
            'demographics' => 'Parents aged 30-50',
            'problems' => ['Uncertainty about daily health'],
            'desired_outcomes' => ['Quick reassurance'],
            'motivations' => ['Protect family health'],
            'purchase_triggers' => ['Easy home measurements'],
            'objections' => ['Questions device accuracy'],
            'products' => ['Home health monitor'],
            'evidence' => ['Ad language about family wellness'],
            'confidence' => 0.9,
            'confirmed' => true,
            'position' => 0,
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'health-demo',
            'library_ad_ids' => ['ad-health-1'],
            'source_snapshot' => ['library_ad_id' => 'ad-health-1', 'body' => 'Check key health signals in sixty seconds.', 'media_type' => 'video'],
            'processing_status' => 'running',
        ]);

        Http::fake(function ($request) use ($persona) {
            $payload = $request->data();
            $prompt = data_get($payload, 'contents.0.parts.0.text', '');
            $this->assertStringContainsString('At-home wellness monitoring', $prompt);
            $this->assertStringContainsString('Questions device accuracy', $prompt);
            $this->assertStringContainsString('Check key health signals in sixty seconds.', $prompt);

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'primary_persona_id' => $persona->id,
                    'secondary_persona_ids' => [],
                    'angle' => 'Fast reassurance',
                    'product' => 'Home health monitor',
                    'what_it_solves' => 'Uncertainty about daily health signals',
                    'format' => 'Problem-solution demonstration',
                    'type' => 'Product demo video',
                    'remarks' => 'Connects quick measurement with the persona desire for reassurance.',
                    'evidence' => ['Sixty-second reading claim', 'Persona wants reassurance'],
                    'confidence' => 0.86,
                ])]]]]],
                'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 40],
            ]);
        });

        app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);

        $creative->refresh();
        $this->assertSame('complete', $creative->processing_status);
        $this->assertSame('Fast reassurance', $creative->angle);
        $this->assertSame('Home health monitor', $creative->product);
        $this->assertSame('Uncertainty about daily health signals', $creative->what_it_solves);
        $this->assertSame('Problem-solution demonstration', $creative->format);
        $this->assertSame('Product demo video', $creative->type);
        $this->assertSame('Connects quick measurement with the persona desire for reassurance.', $creative->remarks);
        $this->assertSame($persona->id, $creative->personas()->wherePivot('role', 'primary')->firstOrFail()->id);
    }

    private function runFor(UserRole $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $search = AdLibrarySearch::create(['created_by' => $user->id, 'company_name' => 'Acme Running', 'company_type' => 'competitor', 'website_url' => 'https://acme.example', 'name' => 'Search', 'search_mode' => 'keyword', 'query' => 'shoes']);
        $run = $search->runs()->create(['requested_by' => $user->id, 'status' => 'complete', 'result_count' => 2]);

        return [$user, $run];
    }
}
