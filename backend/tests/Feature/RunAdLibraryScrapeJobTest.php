<?php

namespace Tests\Feature;

use App\Jobs\RunAdLibraryScrapeJob;
use App\Models\AdLibraryRun;
use App\Models\AdLibrarySearch;
use App\Services\AdLibrary\AdLibraryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunAdLibraryScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_normalized_results_and_is_idempotent(): void
    {
        $run = $this->createRun();
        $provider = new class implements AdLibraryProvider
        {
            public function scrape(AdLibraryRun $run): array
            {
                return ['schema_version' => 1, 'request_id' => (string) $run->id, 'status' => 'complete', 'stop_reason' => 'complete', 'reported_total' => 120, 'capped' => true, 'metadata' => ['engine' => 'playwright'], 'error' => null, 'ads' => [[
                    'ad_id' => '123', 'advertiser_name' => 'Acme', 'body' => 'Buy now', 'is_active' => true,
                    'platforms_json' => '["FACEBOOK","INSTAGRAM"]', 'image_urls_json' => '["https://example.com/a.jpg"]',
                ], [
                    'ad_id' => '123', 'advertiser_name' => 'Duplicate',
                ]]];
            }
        };

        (new RunAdLibraryScrapeJob($run->id))->handle($provider);
        (new RunAdLibraryScrapeJob($run->id))->handle($provider);

        $run->refresh();
        $this->assertSame('complete', $run->status);
        $this->assertTrue($run->capped);
        $this->assertSame(1, $run->result_count);
        $this->assertSame(['FACEBOOK', 'INSTAGRAM'], $run->results()->first()->platforms);
    }

    public function test_partial_response_keeps_usable_rows(): void
    {
        $run = $this->createRun();
        $provider = new class implements AdLibraryProvider
        {
            public function scrape(AdLibraryRun $run): array
            {
                return ['schema_version' => 1, 'request_id' => (string) $run->id, 'status' => 'partial', 'stop_reason' => 'stalled', 'ads' => [['ad_id' => '456']], 'metadata' => []];
            }
        };

        (new RunAdLibraryScrapeJob($run->id))->handle($provider);
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertDatabaseHas('ad_library_results', ['library_ad_id' => '456']);
    }

    public function test_job_preserves_long_creative_text_fields(): void
    {
        $run = $this->createRun();
        $longText = str_repeat('Long creative copy ', 40);
        $provider = new class($longText) implements AdLibraryProvider
        {
            public function __construct(private readonly string $longText) {}

            public function scrape(AdLibraryRun $run): array
            {
                return [
                    'schema_version' => 1,
                    'request_id' => (string) $run->id,
                    'status' => 'complete',
                    'stop_reason' => 'complete',
                    'ads' => [[
                        'ad_id' => 'long-copy',
                        'title' => $this->longText,
                        'link_title' => $this->longText,
                        'link_description' => $this->longText,
                    ]],
                    'metadata' => [],
                ];
            }
        };

        (new RunAdLibraryScrapeJob($run->id))->handle($provider);

        $result = $run->results()->firstOrFail();
        $this->assertSame($longText, $result->title);
        $this->assertSame($longText, $result->link_title);
        $this->assertSame($longText, $result->link_description);
    }

    private function createRun(): AdLibraryRun
    {
        $search = AdLibrarySearch::create(['company_name' => 'Acme Running', 'company_type' => 'competitor', 'name' => 'Search', 'search_mode' => 'keyword', 'query' => 'shoes']);

        return $search->runs()->create(['status' => 'pending']);
    }
}
