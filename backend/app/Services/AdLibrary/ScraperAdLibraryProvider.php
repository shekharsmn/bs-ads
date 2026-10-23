<?php

namespace App\Services\AdLibrary;

use App\Models\AdLibraryRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ScraperAdLibraryProvider implements AdLibraryProvider
{
    public function scrape(AdLibraryRun $run): array
    {
        if (blank(config('services.ad_library.url')) || blank(config('services.ad_library.token'))) {
            throw new RuntimeException('The Ad Library scraper service is not configured.');
        }

        $search = $run->search;
        $payload = [
            'request_id' => (string) $run->id,
            'search_mode' => $search->search_mode,
            'query' => $search->query,
            'advertiser_page_id' => $search->advertiser_page_id,
            'region' => $search->region,
            'active_status' => $search->active_status,
            'ad_type' => $search->ad_type,
            'exact_phrase' => $search->exact_phrase,
            'details' => $search->details,
            'max_results' => $search->max_results,
        ];

        try {
            $response = Http::baseUrl(rtrim(config('services.ad_library.url'), '/'))
                ->withToken(config('services.ad_library.token'))
                ->acceptJson()
                ->connectTimeout(config('services.ad_library.connect_timeout'))
                ->timeout(config('services.ad_library.read_timeout'))
                ->post('/v1/scrapes', $payload);
        } catch (ConnectionException $e) {
            throw new TransientAdLibraryException('The Ad Library scraper could not be reached.', previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new TransientAdLibraryException('The Ad Library scraper is temporarily unavailable.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('The Ad Library scraper rejected the request.');
        }
        if (strlen($response->body()) > config('services.ad_library.max_response_bytes')) {
            throw new RuntimeException('The Ad Library scraper response exceeded the configured size limit.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1 || ! is_array($data['ads'] ?? null) || (string) ($data['request_id'] ?? '') !== (string) $run->id) {
            throw new RuntimeException('The Ad Library scraper returned an invalid response.');
        }

        return $data;
    }
}
