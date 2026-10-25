<?php

namespace Tests\Unit;

use App\Services\AdLibraryAudit\SafePublicUrl;
use App\Services\AdLibraryAudit\WebsiteContextCrawler;
use App\Services\AdLibraryAudit\WebsiteContextFetchException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class WebsiteContextCrawlerTest extends TestCase
{
    public function test_collects_relevant_same_origin_pages_only(): void
    {
        Http::fake([
            'https://brand.test/' => Http::response('<html><head><title>Brand</title></head><body>Homepage <a href="/products">Products</a><a href="https://outside.test/about">About elsewhere</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://brand.test/products' => Http::response('<html><head><title>Products</title></head><body>Vitamin E</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $crawler = new WebsiteContextCrawler($this->permissiveValidator());
        $pages = $crawler->crawl('https://brand.test/');
        $this->assertCount(2, $pages);
        $this->assertSame('Products', $pages[1]['title']);
        Http::assertSent(fn ($request) => $request->url() === 'https://brand.test/'
            && str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla/5.0')
            && str_contains($request->header('Accept')[0] ?? '', 'text/html'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outside.test'));
    }

    public function test_revalidates_redirect_host(): void
    {
        Http::fake(['https://brand.test/' => Http::response('', 302, ['Location' => 'https://outside.test/about'])]);
        $crawler = new WebsiteContextCrawler(new class extends SafePublicUrl
        {
            public function assert(string $url, ?string $requiredHost = null): string
            {
                if ($requiredHost && parse_url($url, PHP_URL_HOST) !== $requiredHost) {
                    throw new InvalidArgumentException('cross origin');
                }

                return $url;
            }
        });
        $this->expectException(InvalidArgumentException::class);
        $crawler->crawl('https://brand.test/');
    }

    public function test_rejects_oversized_html(): void
    {
        config(['ad_library_audit.crawl.max_bytes' => 20]);
        Http::fake(['https://brand.test/' => Http::response(str_repeat('x', 21), 200, ['Content-Type' => 'text/html'])]);
        $this->expectException(WebsiteContextFetchException::class);
        (new WebsiteContextCrawler($this->permissiveValidator()))->crawl('https://brand.test/');
    }

    public function test_reports_rate_limiting_as_a_fetch_failure(): void
    {
        Http::fake(['https://brand.test/' => Http::response('Slow down', 429, ['Retry-After' => '60'])]);

        $this->expectException(WebsiteContextFetchException::class);
        $this->expectExceptionCode(429);

        (new WebsiteContextCrawler($this->permissiveValidator()))->crawl('https://brand.test/');
    }

    private function permissiveValidator(): SafePublicUrl
    {
        return new class extends SafePublicUrl
        {
            public function assert(string $url, ?string $requiredHost = null): string
            {
                return $url;
            }
        };
    }
}
