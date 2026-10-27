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
use App\Services\AdLibraryAudit\CreativeMediaPreprocessor;
use App\Services\AdLibraryAudit\RetryableAiException;
use App\Services\AdLibraryAudit\TerminalAiException;
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

    public function test_audit_detail_flags_creative_analysis_that_stopped_making_progress(): void
    {
        config(['ad_library_audit.analysis_stall_minutes' => 5]);
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create(['company_name' => 'Northstar Labs', 'company_type' => 'competitor', 'created_by' => $manager->id, 'name' => 'Stalled analysis', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'analyzing_creatives']);
        $creative = $audit->creatives()->create(['creative_key' => 'waiting', 'library_ad_ids' => ['waiting'], 'source_snapshot' => [], 'processing_status' => 'pending']);
        $creative->timestamps = false;
        $creative->updated_at = now()->subMinutes(10);
        $creative->save();

        $this->actingAs($manager)->getJson('/api/ad-library/audits/'.$audit->id)
            ->assertOk()
            ->assertJsonPath('analysis_health.stalled', true)
            ->assertJsonPath('analysis_health.message', 'Creative analysis has not made progress. The analysis queue worker may be offline.');
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
            'video_script' => '=spoken formula-like dialogue',
            'video_script_status' => 'captured',
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
        $this->assertStringContainsString("'=spoken formula-like dialogue", $csvContent);

        $sheets = $this->get('/api/ad-library/audits/'.$audit->id.'/export.sheets')
            ->assertOk()
            ->assertHeader('content-type', 'text/tab-separated-values; charset=UTF-8');
        $this->assertStringContainsString("Creative\tLink\tMedia\tVideo script", $sheets->getContent());
        $this->assertStringContainsString("'=unsafe formula", $sheets->getContent());
        $this->assertStringContainsString("'=spoken formula-like dialogue", $sheets->getContent());

        $xlsx = $this->get('/api/ad-library/audits/'.$audit->id.'/export.xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $this->assertSame('PK', substr(file_get_contents($path), 0, 2));
        $archive = new \PharData($path);
        $worksheet = $archive['xl/worksheets/sheet1.xml']->getContent();
        $this->assertStringContainsString('Sixty-second demo', $worksheet);
        $this->assertStringContainsString('Busy parent', $worksheet);
        $this->assertStringContainsString('spoken formula-like dialogue', $worksheet);
        unset($archive);
        unlink($path);

        $this->getJson('/api/ad-library/audits/'.$audit->id.'/export.json')
            ->assertOk()
            ->assertJsonPath('audit.creatives.0.video_script', '=spoken formula-like dialogue')
            ->assertJsonPath('audit.creatives.0.video_script_status', 'captured');
    }

    public function test_final_report_exports_a_formatted_multi_tab_workbook_and_google_sheets_payload(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $audit = AdLibraryAudit::create([
            'company_name' => 'Northstar Labs', 'company_type' => 'competitor', 'created_by' => $viewer->id,
            'name' => 'Northstar final report', 'website_url' => 'https://northstar.test',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'visual', 'status' => 'complete', 'completed_at' => now(),
            'website_context' => ['summary' => 'A focused brand.', 'products' => ['Flagship product']],
            'context_sources' => [['title' => 'Northstar home', 'url' => 'https://northstar.test']],
            'insights' => [
                'executive_summary' => 'Strong product focus with room for more audience variety.',
                'observations_disclaimer' => 'Content observations only.',
                'persona_creative_coverage' => [['persona' => 'Busy parent', 'creative_count' => 1]],
                'persona_product_coverage' => [['persona' => 'Busy parent', 'products' => ['Flagship product']]],
                'angle_distribution' => [['label' => 'Reassurance', 'count' => 1]],
                'format_distribution' => [['label' => 'Demo', 'count' => 1]],
                'type_distribution' => [['label' => 'Video', 'count' => 1]],
                'gaps' => ['Audience variety'], 'content_opportunities' => ['Show another use case'],
                'test_recommendations' => ['Test a proof-led opener'], 'repetition_and_concentration' => [], 'long_running_patterns' => [],
            ],
        ]);
        $persona = $audit->personas()->create(['name' => 'Busy parent', 'description' => 'Needs reassurance', 'products' => ['Flagship product'], 'position' => 0]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'final-report', 'library_ad_ids' => ['final-report-ad'],
            'source_snapshot' => ['title' => 'Product demo', 'media_type' => 'video'],
            'processing_status' => 'complete', 'review_status' => 'reviewed', 'product' => 'Flagship product',
            'video_script' => "Welcome.\nHere is the demonstration.", 'video_script_status' => 'captured',
            'angle' => 'Reassurance', 'format' => 'Demo', 'type' => 'Video', 'confidence' => 0.92,
        ]);
        $creative->personas()->attach($persona->id, ['role' => 'primary']);

        $sheets = $this->actingAs($viewer)->getJson('/api/ad-library/audits/'.$audit->id.'/report.sheets')
            ->assertOk()
            ->assertJsonPath('workbook_name', 'Northstar final report - Final report')
            ->assertJsonCount(9, 'tabs')
            ->assertJsonPath('tabs.0.name', 'Overview')
            ->assertJsonPath('tabs.3.name', 'Creative Audit');
        $this->assertStringContainsString("Field\tValue", $sheets->json('tabs.0.tsv'));
        $this->assertStringContainsString('Product demo', $sheets->json('tabs.3.tsv'));
        $this->assertStringContainsString('Welcome.', $sheets->json('tabs.3.tsv'));

        $xlsx = $this->get('/api/ad-library/audits/'.$audit->id.'/report.xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $xlsx->baseResponse->getFile()->getPathname();
        $archive = new \PharData($path);
        $workbook = $archive['xl/workbook.xml']->getContent();
        $this->assertStringContainsString('name="Overview"', $workbook);
        $this->assertStringContainsString('name="Recommendations"', $workbook);
        $this->assertStringContainsString('Strong product focus', $archive['xl/worksheets/sheet1.xml']->getContent());
        $creativeSheet = $archive['xl/worksheets/sheet4.xml']->getContent();
        $this->assertStringContainsString('<hyperlink ref="B2" r:id="rId1"/>', $creativeSheet);
        $this->assertStringContainsString('Target="https://www.facebook.com/ads/library/?id=final-report-ad"', $archive['xl/worksheets/_rels/sheet4.xml.rels']->getContent());
        $this->assertStringContainsString('Audience variety', $archive['xl/worksheets/sheet8.xml']->getContent());
        $this->assertStringContainsString('wrapText="1"', $archive['xl/styles.xml']->getContent());
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

    public function test_successful_website_crawl_persists_array_context_sources(): void
    {
        $this->mock(WebsiteContextCrawler::class, function ($mock) {
            $mock->shouldReceive('assertSafe')->once()->with('https://brand.test/');
            $mock->shouldReceive('crawl')->once()->with('https://brand.test/')->andReturn([
                ['url' => 'https://brand.test/', 'title' => 'Brand home', 'text' => 'A public brand fact.'],
                ['url' => 'https://brand.test/products', 'title' => 'Products', 'text' => 'A public product fact.'],
            ]);
        });
        Http::fake(fn () => Http::response($this->geminiResponse([
            'summary' => 'A public brand.',
            'facts' => ['A public brand fact.'],
            'products' => ['A public product'],
            'claims' => [],
            'audience_signals' => [],
            'positioning' => [],
            'value_propositions' => [],
            'evidence' => [['fact' => 'A public brand fact.', 'url' => 'https://brand.test/']],
        ])));
        $audit = AdLibraryAudit::create([
            'company_name' => 'Brand',
            'company_type' => 'competitor',
            'name' => 'Crawled context audit',
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
        $this->assertSame([
            ['url' => 'https://brand.test/', 'title' => 'Brand home'],
            ['url' => 'https://brand.test/products', 'title' => 'Products'],
        ], $audit->context_sources);
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
        $this->assertSame('Problem-Agitation-Solution (PAS)', $creative->format);
        $this->assertSame('Product demo video', $creative->type);
        $this->assertSame('Connects quick measurement with the persona desire for reassurance.', $creative->remarks);
        $this->assertSame($persona->id, $creative->personas()->wherePivot('role', 'primary')->firstOrFail()->id);
    }

    public function test_evidence_first_context_confirmation_starts_every_selected_creative(): void
    {
        Bus::fake();
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create([
            'company_name' => 'Evidence Co', 'company_type' => 'client', 'created_by' => $manager->id,
            'name' => 'Evidence first', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite',
            'model_key' => 'gemini:gemini-2.5-flash-lite', 'workflow_version' => 2, 'status' => 'context_review',
        ]);
        $first = $audit->creatives()->create(['creative_key' => 'first', 'library_ad_ids' => ['first'], 'source_snapshot' => []]);
        $second = $audit->creatives()->create(['creative_key' => 'second', 'library_ad_ids' => ['second'], 'source_snapshot' => []]);

        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/context/confirm')
            ->assertAccepted()->assertJsonPath('status', 'analyzing_creatives')->assertJsonPath('progress_total', 2);

        $this->assertSame('pending', $first->fresh()->processing_status);
        $this->assertSame('pending', $second->fresh()->processing_status);
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof AnalyzeAdLibraryCreativeJob));
    }

    public function test_full_media_video_transcribes_before_classification_and_reuses_spoken_script(): void
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Health Brand', 'company_type' => 'client', 'name' => 'Evidence first video',
            'website_context' => ['summary' => 'Home health monitoring'],
            'client_context' => ['name' => 'Clinic Team', 'objective' => 'Build trust', 'goals' => ['Explain the device']],
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'full_media', 'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2,
            'status' => 'analyzing_creatives',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'spoken-demo', 'library_ad_ids' => ['spoken-demo'],
            'source_snapshot' => ['library_ad_id' => 'spoken-demo', 'media_type' => 'video', 'video_urls' => ['https://media.test/demo.mp4']],
            'processing_status' => 'running',
        ]);
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([['mime' => 'video/mp4', 'data' => 'video-bytes']]);
        });
        $requestNumber = 0;
        Http::fake(function ($request) use (&$requestNumber) {
            $requestNumber++;
            $prompt = data_get($request->data(), 'contents.0.parts.0.text', '');
            if ($requestNumber === 1) {
                $this->assertStringContainsString('Do not include on-screen text', $prompt);

                return Http::response($this->geminiResponse(['status' => 'captured', 'transcript' => "Bonjour.\nPrenez votre mesure maintenant."]));
            }
            $this->assertStringContainsString('Home health monitoring', $prompt);
            $this->assertStringContainsString('Clinic Team', $prompt);
            $this->assertStringContainsString('Prenez votre mesure maintenant.', $prompt);

            return Http::response($this->geminiResponse([
                'persona_candidates' => [[
                    'key' => 'reassurance-seeker', 'name' => 'At-home reassurance seeker',
                    'description' => 'Needs a quick reading between appointments.', 'demographics' => '',
                    'problems' => ['Uncertainty between appointments'], 'desired_outcomes' => ['Quick reassurance'],
                    'motivations' => ['Stay informed'], 'purchase_triggers' => ['Simple home reading'],
                    'objections' => ['Accuracy concerns'], 'products' => ['Home monitor'],
                    'evidence' => ['The spoken script asks the viewer to take a measurement now.'], 'confidence' => 0.91,
                ]],
                'angle' => 'Immediate reassurance', 'product' => 'Home monitor',
                'what_it_solves' => 'Uncertainty between appointments', 'format' => 'Spoken demonstration',
                'type' => 'Video', 'remarks' => 'The dialogue directs an immediate measurement.',
                'evidence' => ['Spoken call to take a measurement'], 'confidence' => 0.9,
            ]));
        });

        app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);

        $creative->refresh();
        $this->assertSame('captured', $creative->video_script_status);
        $this->assertSame("Bonjour.\nPrenez votre mesure maintenant.", $creative->video_script);
        $this->assertSame('At-home reassurance seeker', $creative->persona_candidates[0]['name']);
        $this->assertSame(['creative_transcript', 'creative'], $audit->aiRequests()->orderBy('id')->pluck('stage')->all());
    }

    public function test_non_full_media_video_records_that_script_was_not_requested(): void
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Visual Brand', 'company_type' => 'competitor', 'name' => 'Visual audit',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'visual', 'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2,
            'status' => 'analyzing_creatives',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'visual-video', 'library_ad_ids' => ['visual-video'],
            'source_snapshot' => ['library_ad_id' => 'visual-video', 'media_type' => 'video'], 'processing_status' => 'running',
        ]);
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([]);
        });
        Http::fake(fn () => Http::response($this->geminiResponse([
            'persona_candidates' => [[
                'key' => 'convenience', 'name' => 'Convenience seeker', 'description' => 'Needs a simpler option.', 'demographics' => '',
                'problems' => ['Complex setup'], 'desired_outcomes' => ['A simple process'], 'motivations' => ['Save time'],
                'purchase_triggers' => ['Easy setup'], 'objections' => ['Looks complicated'], 'products' => ['Product'],
                'evidence' => ['The ad copy emphasizes simplicity.'], 'confidence' => 0.7,
            ]],
            'angle' => 'Simplicity', 'product' => 'Product', 'what_it_solves' => 'Complex setup',
            'format' => 'Visual demonstration', 'type' => 'Video', 'remarks' => 'Based on available visual and copy evidence.',
            'evidence' => ['Simplicity claim'], 'confidence' => 0.7,
        ])));

        app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);

        $this->assertSame('not_requested', $creative->fresh()->video_script_status);
        $this->assertDatabaseMissing('ad_library_audit_ai_requests', ['ad_library_audit_id' => $audit->id, 'stage' => 'creative_transcript']);
    }

    public function test_full_media_video_with_no_speech_is_still_successfully_classified(): void
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Silent Brand', 'company_type' => 'competitor', 'name' => 'Silent video',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'full_media', 'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2,
            'status' => 'analyzing_creatives',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'silent', 'library_ad_ids' => ['silent'],
            'source_snapshot' => ['media_type' => 'video', 'video_urls' => ['https://media.test/silent.mp4']], 'processing_status' => 'running',
        ]);
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([['mime' => 'video/mp4', 'data' => 'silent-video']]);
        });
        $requestNumber = 0;
        Http::fake(function () use (&$requestNumber) {
            $requestNumber++;

            return Http::response($this->geminiResponse($requestNumber === 1
                ? ['status' => 'no_speech', 'transcript' => '']
                : $this->creativeEvidenceResponse()));
        });

        app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);

        $creative->refresh();
        $this->assertSame('complete', $creative->processing_status);
        $this->assertSame('no_speech', $creative->video_script_status);
        $this->assertNull($creative->video_script);
    }

    public function test_full_media_video_without_downloadable_media_records_unavailable_and_continues(): void
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Unavailable Brand', 'company_type' => 'competitor', 'name' => 'Unavailable video',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'full_media', 'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2,
            'status' => 'analyzing_creatives',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'unavailable', 'library_ad_ids' => ['unavailable'],
            'source_snapshot' => ['media_type' => 'video', 'video_urls' => ['https://media.test/missing.mp4']], 'processing_status' => 'running',
        ]);
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([]);
        });
        Http::fake(fn () => Http::response($this->geminiResponse($this->creativeEvidenceResponse())));

        app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);

        $creative->refresh();
        $this->assertSame('complete', $creative->processing_status);
        $this->assertSame('unavailable', $creative->video_script_status);
        $this->assertDatabaseMissing('ad_library_audit_ai_requests', ['ad_library_audit_id' => $audit->id, 'stage' => 'creative_transcript']);
    }

    public function test_transient_transcription_failure_is_retryable_and_records_failed_status(): void
    {
        [$audit, $creative] = $this->fullMediaAuditCreative('transient-transcript');
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([['mime' => 'video/mp4', 'data' => 'video']]);
        });
        Http::fake(fn () => Http::response(['error' => ['message' => 'busy']], 429));

        try {
            app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);
            $this->fail('A transient transcription failure should be retryable.');
        } catch (RetryableAiException) {
            $this->assertSame('failed', $creative->fresh()->video_script_status);
            $this->assertDatabaseHas('ad_library_audit_ai_requests', ['ad_library_audit_id' => $audit->id, 'stage' => 'creative_transcript', 'status' => 'failed']);
        }
    }

    public function test_terminal_transcription_failure_records_failed_status_without_reconstruction(): void
    {
        [$audit, $creative] = $this->fullMediaAuditCreative('terminal-transcript');
        $this->mock(CreativeMediaPreprocessor::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([['mime' => 'video/mp4', 'data' => 'video']]);
        });
        Http::fake(fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{not valid json']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2],
        ]));

        try {
            app(AdLibraryAuditWorkflow::class)->analyzeCreative($creative);
            $this->fail('Malformed transcript output should fail terminally.');
        } catch (TerminalAiException) {
            $creative->refresh();
            $this->assertSame('failed', $creative->video_script_status);
            $this->assertNull($creative->video_script);
            $this->assertDatabaseHas('ad_library_audit_ai_requests', ['ad_library_audit_id' => $audit->id, 'stage' => 'creative_transcript', 'status' => 'failed']);
        }
    }

    public function test_consolidation_assigns_exactly_one_primary_with_rationale_and_confirmation_rejects_orphans(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $audit = AdLibraryAudit::create([
            'company_name' => 'Evidence Co', 'company_type' => 'client', 'created_by' => $manager->id, 'name' => 'Consolidation',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2, 'status' => 'consolidating_personas',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => 'one', 'library_ad_ids' => ['one'], 'source_snapshot' => [], 'processing_status' => 'complete',
            'angle' => 'Reassurance', 'product' => 'Monitor', 'what_it_solves' => 'Uncertainty', 'format' => 'Demo', 'type' => 'Video',
            'remarks' => 'Shows a quick reading.', 'evidence' => ['Quick reading'], 'confidence' => 0.85,
            'persona_candidates' => [['key' => 'local', 'name' => 'Reassurance seeker', 'evidence' => ['Quick reading']]],
        ]);
        Http::fake(fn () => Http::response($this->geminiResponse([
            'personas' => [[
                'key' => 'reassurance', 'name' => 'Reassurance seeker', 'description' => 'Wants confidence from a quick reading.', 'demographics' => '',
                'problems' => ['Uncertainty'], 'desired_outcomes' => ['Confidence'], 'motivations' => ['Stay informed'],
                'purchase_triggers' => ['Quick reading'], 'objections' => ['Accuracy'], 'products' => ['Monitor'],
                'evidence' => ['Creative one shows a quick reading.'], 'confidence' => 0.88,
            ]],
            'assignments' => [[
                'creative_id' => $creative->id, 'primary_persona_key' => 'reassurance', 'secondary_persona_keys' => [],
                'rationale' => 'The creative directly addresses uncertainty with a quick reading.', 'confidence' => 0.93,
            ]],
        ])));

        app(AdLibraryAuditWorkflow::class)->consolidatePersonas($audit);

        $creative->refresh();
        $this->assertSame('persona_review', $audit->fresh()->status);
        $primary = $creative->personas()->wherePivot('role', 'primary')->firstOrFail();
        $this->assertSame('Reassurance seeker', $primary->name);
        $this->assertSame('The creative directly addresses uncertainty with a quick reading.', $primary->pivot->rationale);
        $this->assertSame(0.93, (float) $primary->pivot->confidence);
        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/personas/confirm')
            ->assertOk()->assertJsonPath('status', 'creative_review');

        $primary->delete();
        $audit->refresh();
        $audit->update(['status' => 'persona_review']);
        $audit->personas()->create(['name' => 'Replacement', 'description' => 'Manual replacement', 'position' => 0]);
        $this->actingAs($manager)->postJson('/api/ad-library/audits/'.$audit->id.'/personas/confirm')->assertStatus(422);
    }

    private function geminiResponse(array $data): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 40],
        ];
    }

    private function creativeEvidenceResponse(): array
    {
        return [
            'persona_candidates' => [[
                'key' => 'evidence-led', 'name' => 'Evidence-led buyer', 'description' => 'Needs a clear reason to act.', 'demographics' => '',
                'problems' => ['Uncertainty'], 'desired_outcomes' => ['Clarity'], 'motivations' => ['Make an informed choice'],
                'purchase_triggers' => ['Clear demonstration'], 'objections' => ['Needs proof'], 'products' => ['Product'],
                'evidence' => ['The creative demonstrates the product.'], 'confidence' => 0.72,
            ]],
            'angle' => 'Clear demonstration', 'product' => 'Product', 'what_it_solves' => 'Uncertainty',
            'format' => 'Demonstration', 'type' => 'Video', 'remarks' => 'Classification uses the available creative evidence.',
            'evidence' => ['Product demonstration'], 'confidence' => 0.72,
        ];
    }

    private function fullMediaAuditCreative(string $key): array
    {
        $audit = AdLibraryAudit::create([
            'company_name' => 'Transcript Brand', 'company_type' => 'competitor', 'name' => 'Transcript failure',
            'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite',
            'media_depth' => 'full_media', 'workflow_version' => 2, 'prompt_version' => 2, 'schema_version' => 2,
            'status' => 'analyzing_creatives',
        ]);
        $creative = $audit->creatives()->create([
            'creative_key' => $key, 'library_ad_ids' => [$key],
            'source_snapshot' => ['media_type' => 'video', 'video_urls' => ['https://media.test/'.$key.'.mp4']], 'processing_status' => 'running',
        ]);

        return [$audit, $creative];
    }

    private function runFor(UserRole $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $search = AdLibrarySearch::create(['created_by' => $user->id, 'company_name' => 'Acme Running', 'company_type' => 'competitor', 'website_url' => 'https://acme.example', 'name' => 'Search', 'search_mode' => 'keyword', 'query' => 'shoes']);
        $run = $search->runs()->create(['requested_by' => $user->id, 'status' => 'complete', 'result_count' => 2]);

        return [$user, $run];
    }
}
