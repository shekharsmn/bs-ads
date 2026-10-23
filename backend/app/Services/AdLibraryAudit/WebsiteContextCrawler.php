<?php

namespace App\Services\AdLibraryAudit;

use DOMDocument;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WebsiteContextCrawler
{
    public function __construct(private readonly SafePublicUrl $safeUrl) {}

    public function crawl(string $startUrl): array
    {
        $this->safeUrl->assert($startUrl);
        $host = strtolower((string) parse_url($startUrl, PHP_URL_HOST));
        $queue = [$startUrl];
        $seen = [];
        $pages = [];
        $limit = (int) config('ad_library_audit.crawl.max_pages', 5);

        while ($queue !== [] && count($pages) < $limit) {
            $url = array_shift($queue);
            $key = $this->canonical($url);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            [$finalUrl, $response] = $this->fetchFollowingRedirects($url, $host);
            if (! $response->successful()) continue;
            $contentType = strtolower($response->header('Content-Type', ''));
            $body = $response->body();
            if (! str_contains($contentType, 'text/html') || strlen($body) > (int) config('ad_library_audit.crawl.max_bytes', 1_000_000)) continue;

            [$title, $text, $links] = $this->extract($body, $finalUrl, $host);
            $pages[] = ['url' => $finalUrl, 'title' => $title, 'text' => mb_substr($text, 0, 15_000)];
            foreach ($links as $link) {
                if (! isset($seen[$this->canonical($link)])) $queue[] = $link;
            }
        }

        if ($pages === []) throw new InvalidArgumentException('No usable public HTML pages could be collected.');
        return $pages;
    }

    private function fetchFollowingRedirects(string $url, string $host): array
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $this->safeUrl->assert($url, $host);
            $maxBytes = (int) config('ad_library_audit.crawl.max_bytes', 1_000_000);
            $response = Http::withOptions(['allow_redirects' => false, 'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                if (($downloadTotal > 0 && $downloadTotal > $maxBytes) || $downloaded > $maxBytes) throw new InvalidArgumentException('The website page exceeded the size limit.');
            }])
                ->timeout((int) config('ad_library_audit.crawl.timeout', 10))
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'User-Agent' => (string) config('ad_library_audit.crawl.user_agent'),
                ])
                ->get($url);
            if ($response->status() === 429) {
                throw new RetryableContextException('The website temporarily rate-limited context collection.', 429);
            }
            if ($response->serverError()) {
                throw new RetryableContextException('The website was temporarily unavailable during context collection.', $response->status());
            }
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) return [$url, $response];
            $location = $response->header('Location');
            if (! $location) throw new InvalidArgumentException('The website returned an invalid redirect.');
            $url = $this->absoluteUrl($url, $location);
        }
        throw new InvalidArgumentException('The website redirected too many times.');
    }

    private function extract(string $html, string $baseUrl, string $host): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach (['script', 'style', 'noscript', 'svg'] as $tag) {
            while (($nodes = $dom->getElementsByTagName($tag))->length) $nodes->item(0)?->parentNode?->removeChild($nodes->item(0));
        }
        $title = trim($dom->getElementsByTagName('title')->item(0)?->textContent ?? '');
        $text = preg_replace('/\s+/u', ' ', trim($dom->textContent ?? '')) ?: '';
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) continue;
            $absolute = $this->absoluteUrl($baseUrl, $href);
            if (strtolower((string) parse_url($absolute, PHP_URL_HOST)) !== $host) continue;
            $haystack = strtolower($absolute.' '.$anchor->textContent);
            if (preg_match('/product|collection|shop|about|benefit|faq/', $haystack)) $links[] = $absolute;
        }
        return [$title, $text, array_values(array_unique($links))];
    }

    private function absoluteUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) return $relative;
        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($relative, '//')) return ($parts['scheme'] ?? 'https').':'.$relative;
        if (str_starts_with($relative, '/')) return $root.$relative;
        $path = (string) ($parts['path'] ?? '/');
        return $root.rtrim(str_replace('\\', '/', dirname($path)), '/').'/'.$relative;
    }

    private function canonical(string $url): string
    {
        return strtolower(rtrim((string) preg_replace('/#.*$/', '', $url), '/'));
    }
}
