<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAdLibraryScrapeJob;
use App\Models\AdLibraryResult;
use App\Models\AdLibraryRun;
use App\Models\AdLibrarySearch;
use App\Models\AdLibraryTag;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdLibraryController extends Controller
{
    public function searches(Request $request)
    {
        $this->enabled();
        $data = $request->validate(['company_type' => ['nullable', Rule::in($this->companyTypes())], 'tag_id' => 'nullable|integer|exists:ad_library_tags,id']);

        return ['searches' => AdLibrarySearch::query()
            ->when($data['company_type'] ?? null, fn ($query, $type) => $query->where('company_type', $type))
            ->when($data['tag_id'] ?? null, fn ($query, $id) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($id)))
            ->with('tags:id,name,slug')->withCount('runs')->latest()->get(), 'tags' => AdLibraryTag::query()->orderBy('name')->get(['id', 'name', 'slug'])];
    }

    public function store(Request $request)
    {
        $this->enabled();
        $data = $this->validateSearch($request);
        $this->manage($request);
        $data = $this->normalizeSearch($data);
        $data['created_by'] = $request->user()->id;

        return response()->json(AdLibrarySearch::create($data), 201);
    }

    public function update(Request $request, AdLibrarySearch $search)
    {
        $this->enabled();
        $this->manage($request);
        $data = $this->validateSearch($request, true);
        $candidate = array_merge($search->only(['company_name', 'company_type', 'website_url', 'name', 'search_mode', 'query', 'advertiser_page_id', 'region', 'active_status', 'ad_type', 'exact_phrase', 'details', 'max_results']), $data);
        $search->update($this->normalizeSearch($candidate));

        return $search->fresh();
    }

    public function destroySearch(Request $request, AdLibrarySearch $search)
    {
        $this->enabled();
        $this->manage($request);
        $search->delete();

        return response()->noContent();
    }

    public function run(Request $request, AdLibrarySearch $search)
    {
        $this->enabled();
        $this->manage($request);
        $run = $search->runs()->create(['requested_by' => $request->user()->id, 'provider' => 'scraper', 'status' => 'pending']);
        RunAdLibraryScrapeJob::dispatch($run->id);

        return response()->json($this->runPayload($run), 202);
    }

    public function runs(Request $request)
    {
        $this->enabled();
        $data = $request->validate(['search_id' => 'nullable|integer', 'company_type' => ['nullable', Rule::in($this->companyTypes())], 'status' => ['nullable', Rule::in(['pending', 'running', 'complete', 'partial', 'empty', 'blocked', 'failed'])]]);
        $runs = AdLibraryRun::query()
            ->when($data['search_id'] ?? null, fn ($q, $id) => $q->where('ad_library_search_id', $id))
            ->when($data['company_type'] ?? null, fn ($q, $type) => $q->whereHas('search', fn ($search) => $search->where('company_type', $type)))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with('search:id,name,deleted_at')->latest()->paginate(25);
        $runs->getCollection()->transform(fn ($run) => $this->runPayload($run));

        return $runs;
    }

    public function showRun(Request $request, AdLibraryRun $run)
    {
        $this->enabled();

        return $this->runPayload($run->load('search:id,name,deleted_at'));
    }

    public function results(Request $request, AdLibraryRun $run)
    {
        $this->enabled();
        $data = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'q' => 'nullable|string|max:100',
            'media_type' => ['nullable', Rule::in(['all', 'static', 'video'])],
            'favorites' => 'nullable|boolean',
        ]);

        $favoriteIds = $request->user()->belongsToMany(AdLibraryResult::class, 'ad_library_favorites')->pluck('ad_library_results.id');

        $rows = $run->results()
            ->when($data['favorites'] ?? false, fn ($query) => $query->whereIn('id', $favoriteIds))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($nested) => $nested
                ->where('advertiser_name', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('link_title', 'like', "%{$term}%")))
            ->orderByRaw('rank IS NULL')
            ->orderBy('rank')
            ->get();

        $mediaType = $data['media_type'] ?? 'all';
        if ($mediaType !== 'all') {
            $rows = $rows->filter(fn (AdLibraryResult $result) => $this->mediaType($result) === $mediaType)->values();
        }

        $rawTotal = $rows->count();
        $creatives = $rows->groupBy(fn (AdLibraryResult $result) => $this->creativeFingerprint($result))
            ->map(function ($variants, $fingerprint) use ($favoriteIds) {
                /** @var AdLibraryResult $primary */
                $primary = $variants->first();
                $payload = $primary->toArray();
                $payload['creative_key'] = $fingerprint;
                $payload['media_type'] = $this->mediaType($primary);
                $payload['variant_count'] = $variants->count();
                $payload['favorite'] = $variants->pluck('id')->intersect($favoriteIds)->isNotEmpty();
                $payload['variants'] = $variants->map(fn (AdLibraryResult $variant) => [
                    'library_ad_id' => $variant->library_ad_id,
                    'product_url' => $variant->product_url,
                    'is_active' => $variant->is_active,
                    'first_shown_at' => optional($variant->first_shown_at)->toDateString(),
                    'last_shown_at' => optional($variant->last_shown_at)->toDateString(),
                    'collation_id' => $variant->raw_payload['collation_id'] ?? null,
                    'versions' => $variant->raw_payload['versions'] ?? null,
                ])->values();

                return $payload;
            })->values();

        $perPage = $data['per_page'] ?? 24;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $creatives->forPage($page, $perPage)->values(),
            $creatives->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json(array_merge($paginator->toArray(), [
            'raw_total' => $rawTotal,
            'duplicate_count' => $rawTotal - $creatives->count(),
        ]));
    }

    private function mediaType(AdLibraryResult $result): string
    {
        return count($result->video_urls ?? []) > 0 ? 'video' : 'static';
    }

    private function creativeFingerprint(AdLibraryResult $result): string
    {
        $collationId = $result->raw_payload['collation_id'] ?? null;
        if (is_string($collationId) && $collationId !== '') {
            return 'collation:'.$collationId;
        }

        $mediaType = $this->mediaType($result);
        $mediaUrls = $mediaType === 'video' ? ($result->video_urls ?? []) : ($result->image_urls ?? []);
        $media = collect($mediaUrls)
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->map(fn ($url) => $this->canonicalMediaUrl($url))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $identity = [
            'media_type' => $mediaType,
            'media' => $media,
            'advertiser_page_id' => $result->advertiser_page_id,
            'display_format' => $result->display_format,
            'body' => $this->fingerprintText($result->body),
            'title' => $this->fingerprintText($result->title),
            'link_title' => $this->fingerprintText($result->link_title),
            'link_description' => $this->fingerprintText($result->link_description),
            'cta_text' => $this->fingerprintText($result->cta_text),
        ];

        if ($media === [] && collect([
            $result->advertiser_page_id,
            $result->display_format,
            $result->body,
            $result->title,
            $result->link_title,
            $result->link_description,
            $result->cta_text,
        ])->every(fn ($value) => blank($value))) {
            return 'ad:'.$result->library_ad_id;
        }

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalMediaUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        return strtolower($parts['host'] ?? '').($parts['path'] ?? $url);
    }

    private function fingerprintText(?string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value ?? '')) ?? '';
    }

    public function runJson(Request $request, AdLibraryRun $run)
    {
        $this->enabled();

        $ads = $run->results()
            ->orderByRaw('rank IS NULL')
            ->orderBy('rank')
            ->get(['raw_payload'])
            ->pluck('raw_payload')
            ->values();

        return response()->json([
            'schema_version' => 1,
            'request_id' => (string) $run->id,
            'status' => $run->status,
            'stop_reason' => $run->stop_reason,
            'reported_total' => $run->reported_total,
            'capped' => $run->capped,
            'ads' => $ads,
            'metadata' => $run->provider_metadata ?? (object) [],
            'error' => $run->error_message,
        ]);
    }

    public function destroyRun(Request $request, AdLibraryRun $run)
    {
        $this->enabled();
        $this->manage($request);
        abort_if(in_array($run->status, ['pending', 'running'], true), 409, 'A running search cannot be deleted.');
        $run->delete();

        return response()->noContent();
    }

    public function storeTag(Request $request)
    {
        $this->enabled();
        $this->manage($request);
        $data = $request->validate(['name' => 'required|string|max:60']);
        $name = trim($data['name']);

        return response()->json(AdLibraryTag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'created_by' => $request->user()->id]), 201);
    }

    public function syncTags(Request $request, AdLibrarySearch $search)
    {
        $this->enabled();
        $this->manage($request);
        $data = $request->validate(['tag_ids' => 'required|array|max:20', 'tag_ids.*' => 'integer|distinct|exists:ad_library_tags,id']);
        $search->tags()->sync($data['tag_ids']);

        return $search->load('tags:id,name,slug');
    }

    public function favorite(Request $request, AdLibraryResult $result)
    {
        $this->enabled();
        $data = $request->validate(['favorite' => 'required|boolean']);
        $request->user()->belongsToMany(AdLibraryResult::class, 'ad_library_favorites')->syncWithoutDetaching($data['favorite'] ? [$result->id] : []);
        if (! $data['favorite']) {
            $request->user()->belongsToMany(AdLibraryResult::class, 'ad_library_favorites')->detach($result->id);
        }

        return ['favorite' => $data['favorite']];
    }

    private function validateSearch(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';

        return $request->validate([
            'company_name' => $prefix.'string|max:160', 'company_type' => [$partial ? 'sometimes' : 'required', Rule::in($this->companyTypes())],
            'website_url' => 'nullable|url:http,https|max:2000', 'name' => $prefix.'string|max:120',
            'search_mode' => $prefix.'in:keyword,advertiser', 'query' => 'nullable|string|max:255',
            'advertiser_page_id' => ['nullable', 'string', 'max:64', 'regex:/^\d+$/'],
            'region' => ['sometimes', 'string', 'max:3', 'regex:/^(ALL|[A-Z]{2})$/'],
            'active_status' => 'sometimes|in:active,inactive,all', 'ad_type' => 'sometimes|in:all,political_and_issue_ads',
            'exact_phrase' => 'sometimes|boolean', 'details' => 'sometimes|boolean', 'max_results' => 'sometimes|integer|min:1|max:500',
        ]);
    }

    private function normalizeSearch(array $data): array
    {
        $mode = $data['search_mode'];
        abort_if($mode === 'keyword' && blank($data['query'] ?? null), 422, 'A keyword is required.');
        abort_if($mode === 'advertiser' && blank($data['advertiser_page_id'] ?? null), 422, 'An advertiser Page ID is required.');
        $data['query'] = $mode === 'keyword' ? trim($data['query']) : null;
        $data['company_name'] = trim($data['company_name']);
        $data['advertiser_page_id'] = $mode === 'advertiser' ? $data['advertiser_page_id'] : null;
        $data['exact_phrase'] = $mode === 'keyword' && (bool) ($data['exact_phrase'] ?? false);
        $data['region'] = strtoupper($data['region'] ?? 'ALL');
        $data['active_status'] ??= 'active';
        $data['ad_type'] ??= 'all';
        $data['details'] ??= false;
        $data['max_results'] ??= 100;
        abort_if($data['details'] && $data['max_results'] > 50, 422, 'Detailed searches are limited to 50 ads.');

        return $data;
    }

    private function runPayload(AdLibraryRun $run): array
    {
        return $run->toArray();
    }

    private function enabled(): void
    {
        abort_unless(config('services.ad_library.enabled'), 404);
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->canManage(), 403, 'Read-only users cannot modify Ad Library research.');
    }

    private function companyTypes(): array
    {
        return ['client', 'competitor', 'lead', 'partner', 'other'];
    }
}
