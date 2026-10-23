<?php

namespace Tests\Unit;

use App\Models\AdLibraryRun;
use App\Models\AdLibrarySearch;
use App\Services\AdLibrary\ScraperAdLibraryProvider;
use App\Services\AdLibrary\TransientAdLibraryException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ScraperAdLibraryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ad_library.url' => 'http://scraper.test', 'services.ad_library.token' => 'secret', 'services.ad_library.connect_timeout' => 1, 'services.ad_library.read_timeout' => 10, 'services.ad_library.max_response_bytes' => 10000]);
    }

    public function test_valid_response_is_returned(): void
    {
        Http::fake(['scraper.test/*' => Http::response(['schema_version' => 1, 'request_id' => '7', 'status' => 'complete', 'ads' => []])]);
        $result = (new ScraperAdLibraryProvider)->scrape($this->makeRun());
        $this->assertSame('complete', $result['status']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret') && $request['query'] === 'shoes');
    }

    public function test_transient_server_failure_is_retryable(): void
    {
        Http::fake(['scraper.test/*' => Http::response([], 503)]);
        $this->expectException(TransientAdLibraryException::class);
        (new ScraperAdLibraryProvider)->scrape($this->makeRun());
    }

    public function test_malformed_response_is_rejected(): void
    {
        Http::fake(['scraper.test/*' => Http::response(['schema_version' => 2, 'ads' => []])]);
        $this->expectException(RuntimeException::class);
        (new ScraperAdLibraryProvider)->scrape($this->makeRun());
    }

    private function makeRun(): AdLibraryRun
    {
        $search = new AdLibrarySearch(['search_mode' => 'keyword', 'query' => 'shoes', 'region' => 'US', 'active_status' => 'active', 'ad_type' => 'all', 'max_results' => 100]);
        $run = new AdLibraryRun(['status' => 'pending']);
        $run->id = 7;
        $run->setRelation('search', $search);

        return $run;
    }
}
