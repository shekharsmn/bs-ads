<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeAdLibraryCreativeJob;
use App\Jobs\CollectAdLibraryAuditContextJob;
use App\Jobs\GenerateAdLibraryInsightsJob;
use App\Jobs\GenerateAdLibraryPersonasJob;
use App\Models\AdLibraryAudit;
use App\Models\AdLibraryAuditCreative;
use App\Models\AdLibraryAuditPersona;
use App\Models\AdLibraryResult;
use App\Models\AdLibraryRun;
use App\Services\AdLibraryAudit\ModelCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdLibraryAuditController extends Controller
{
    public function models(Request $request, ModelCatalog $catalog)
    {
        $this->enabled();
        $data = $request->validate(['media_depth' => ['nullable', Rule::in(['text_only', 'visual', 'full_media'])]]);

        $models = $catalog->available($data['media_depth'] ?? null);
        return ['models' => $models, 'default' => collect($models)->firstWhere('is_default', true)['key'] ?? ($models[0]['key'] ?? null)];
    }

    public function index(Request $request)
    {
        $this->enabled();
        $data = $request->validate(['company_type' => ['nullable', Rule::in($this->companyTypes())]]);

        return AdLibraryAudit::query()
            ->when($data['company_type'] ?? null, fn ($query, $type) => $query->where('company_type', $type))
            ->withCount(['personas', 'creatives'])->with('run.search:id,name,company_name,company_type')->latest()->paginate(25);
    }

    public function store(Request $request, ModelCatalog $catalog)
    {
        $this->enabled();
        $data = $request->validate([
            'run_id' => 'required|integer|exists:ad_library_runs,id',
            'name' => 'required|string|max:160', 'website_url' => 'nullable|url:http,https|max:2000',
            'media_depth' => ['required', Rule::in(['text_only', 'visual', 'full_media'])], 'model_key' => 'required|string|max:180',
            'result_ids' => 'required|array|min:1|max:'.config('ad_library_audit.max_creatives', 50), 'result_ids.*' => 'integer|distinct',
        ]);
        $this->manage($request);
        $run = AdLibraryRun::with('search')->findOrFail($data['run_id']);
        abort_unless(in_array($run->status, ['complete', 'partial'], true), 422, 'Only completed runs with usable results can be audited.');
        $model = $catalog->get($data['model_key'], $data['media_depth']);
        $groups = $this->groupedResults($run);
        $selected = $groups->filter(fn ($group) => in_array($group->first()->id, array_map('intval', $data['result_ids']), true))->values();
        abort_if($selected->count() !== count($data['result_ids']), 422, 'One or more selected creatives do not belong to this run or are duplicate variants.');

        $audit = DB::transaction(function () use ($data, $request, $run, $model, $selected) {
            $audit = AdLibraryAudit::create([
                'ad_library_run_id' => $run->id, 'created_by' => $request->user()->id,
                'company_name' => $run->search->company_name, 'company_type' => $run->search->company_type,
                'name' => $data['name'], 'website_url' => $data['website_url'] ?? $run->search->website_url,
                'provider' => $model['provider'], 'model' => $model['model'], 'model_key' => $model['key'],
                'media_depth' => $data['media_depth'], 'status' => 'collecting_context', 'progress_total' => $selected->count(),
            ]);
            foreach ($selected as $position => $variants) {
                /** @var AdLibraryResult $primary */
                $primary = $variants->first();
                $snapshot = $primary->toArray();
                $snapshot['media_type'] = count($primary->video_urls ?? []) ? 'video' : 'static';
                $snapshot['variant_count'] = $variants->count();
                $snapshot['variants'] = $variants->map(fn ($row) => ['library_ad_id' => $row->library_ad_id, 'is_active' => $row->is_active, 'first_shown_at' => optional($row->first_shown_at)->toDateString(), 'last_shown_at' => optional($row->last_shown_at)->toDateString()])->values()->all();
                $audit->creatives()->create(['ad_library_result_id' => $primary->id, 'creative_key' => $this->fingerprint($primary), 'library_ad_ids' => $variants->pluck('library_ad_id')->values()->all(), 'source_snapshot' => $snapshot, 'position' => $position]);
            }

            return $audit;
        });
        CollectAdLibraryAuditContextJob::dispatch($audit->id);

        return response()->json($this->payload($audit), 202);
    }

    public function show(Request $request, AdLibraryAudit $audit)
    {
        $this->enabled();

        return $this->payload($audit);
    }

    public function update(Request $request, AdLibraryAudit $audit, ModelCatalog $catalog)
    {
        $this->manage($request);
        $data = $request->validate(['company_name' => 'sometimes|string|max:160', 'company_type' => ['sometimes', Rule::in($this->companyTypes())], 'name' => 'sometimes|string|max:160', 'website_url' => 'nullable|url:http,https|max:2000', 'media_depth' => ['sometimes', Rule::in(['text_only', 'visual', 'full_media'])], 'model_key' => 'sometimes|string|max:180']);
        if ((isset($data['model_key']) || isset($data['media_depth'])) && ! in_array($audit->status, ['draft', 'context_review'], true)) {
            abort(409, 'The audit model and media depth are locked after persona generation starts.');
        }
        $depth = $data['media_depth'] ?? $audit->media_depth;
        if (isset($data['model_key']) || isset($data['media_depth'])) {
            $model = $catalog->get($data['model_key'] ?? $audit->model_key, $depth);
            $data += ['provider' => $model['provider'], 'model' => $model['model'], 'model_key' => $model['key']];
        }
        $audit->update($data);

        return $this->payload($audit);
    }

    public function destroy(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request);
        $audit->delete();

        return response()->noContent();
    }

    public function collectContext(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless(in_array($audit->status, ['draft', 'context_review', 'failed'], true), 409);
        $audit->update(['status' => 'collecting_context', 'failed_stage' => null, 'error_message' => null]);
        CollectAdLibraryAuditContextJob::dispatch($audit->id);

        return response()->json($this->payload($audit), 202);
    }

    public function updateContext(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless(in_array($audit->status, ['context_review', 'persona_review'], true) || ($audit->status === 'failed' && $audit->failed_stage === 'context'), 409, 'Context can only be edited before creative analysis starts.');
        $data = $request->validate(['website_context' => 'required|array', 'website_context.summary' => 'nullable|string|max:5000', 'website_context.facts' => 'array|max:100', 'website_context.products' => 'array|max:100', 'website_context.claims' => 'array|max:100', 'website_context.audience_signals' => 'array|max:100', 'website_context.positioning' => 'array|max:100', 'website_context.value_propositions' => 'array|max:100']);
        $audit->update(['website_context' => $data['website_context'], 'status' => 'context_review', 'error_message' => null]);

        return $this->payload($audit);
    }

    public function generatePersonas(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless(in_array($audit->status, ['context_review', 'persona_review', 'failed'], true), 409);
        $audit->update(['status' => 'generating_personas', 'failed_stage' => null, 'error_message' => null]);
        GenerateAdLibraryPersonasJob::dispatch($audit->id);

        return response()->json($this->payload($audit), 202);
    }

    public function storePersona(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        $this->personaEditable($audit);
        abort_if($audit->personas()->count() >= 10, 422, 'An audit can have at most ten personas.');
        $data = $this->personaData($request);
        $data['position'] = $audit->personas()->max('position') + 1;
        $data['manually_edited'] = true;
        $data['edited_by'] = $request->user()->id;
        $data['edited_at'] = now();
        $persona = $audit->personas()->create($data);
        $this->invalidateAfterPersonaChange($audit);

        return response()->json($persona, 201);
    }

    public function updatePersona(Request $request, AdLibraryAudit $audit, AdLibraryAuditPersona $persona)
    {
        $this->manage($request, $audit);
        $this->personaEditable($audit);
        $this->ownsPersona($audit, $persona);
        $persona->update($this->personaData($request, true) + ['manually_edited' => true, 'edited_by' => $request->user()->id, 'edited_at' => now()]);
        $this->invalidateAfterPersonaChange($audit);

        return $persona->fresh();
    }

    public function destroyPersona(Request $request, AdLibraryAudit $audit, AdLibraryAuditPersona $persona)
    {
        $this->manage($request, $audit);
        $this->personaEditable($audit);
        $this->ownsPersona($audit, $persona);
        abort_if($audit->personas()->count() <= 1, 422, 'At least one persona is required.');
        $persona->delete();
        $this->invalidateAfterPersonaChange($audit);

        return response()->noContent();
    }

    public function reorderPersonas(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        $this->personaEditable($audit);
        $data = $request->validate(['persona_ids' => 'required|array|min:1|max:10', 'persona_ids.*' => 'integer|distinct']);
        abort_unless($audit->personas()->whereIn('id', $data['persona_ids'])->count() === count($data['persona_ids']) && $audit->personas()->count() === count($data['persona_ids']), 422);
        foreach ($data['persona_ids'] as $position => $id) {
            $audit->personas()->whereKey($id)->update(['position' => $position, 'manually_edited' => true, 'edited_by' => $request->user()->id, 'edited_at' => now()]);
        }
        $this->invalidateAfterPersonaChange($audit);

        return $this->payload($audit);
    }

    public function confirmPersonas(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless($audit->status === 'persona_review', 409);
        $count = $audit->personas()->count();
        abort_unless($count >= 1 && $count <= 10, 422);
        $audit->personas()->update(['confirmed' => true]);
        $audit->update(['personas_confirmed_at' => now(), 'status' => 'persona_review', 'error_message' => null]);

        return $this->payload($audit);
    }

    public function analyze(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless($audit->status === 'persona_review', 409);
        abort_unless($audit->personas_confirmed_at, 422, 'Confirm personas before analyzing creatives.');
        $ids = $audit->creatives()->pluck('id');
        abort_if($ids->isEmpty(), 422);
        $audit->creatives()->update(['processing_status' => 'pending', 'error_message' => null]);
        $audit->update(['status' => 'analyzing_creatives', 'progress_completed' => 0, 'progress_failed' => 0, 'progress_total' => $ids->count(), 'grid_confirmed_at' => null, 'insights' => null, 'insights_stale' => false]);
        Bus::batch($ids->map(fn ($id) => new AnalyzeAdLibraryCreativeJob($id))->all())->name("Ad Library audit {$audit->id}")->onQueue('ad-library-analysis')->allowFailures()->dispatch();

        return response()->json($this->payload($audit), 202);
    }

    public function retryFailed(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless($audit->status === 'creative_review', 409);
        $ids = $audit->creatives()->where('processing_status', 'failed')->pluck('id');
        abort_if($ids->isEmpty(), 422, 'There are no failed creatives to retry.');
        $audit->creatives()->whereIn('id', $ids)->update(['processing_status' => 'pending', 'error_message' => null]);
        $audit->update(['status' => 'analyzing_creatives']);
        Bus::batch($ids->map(fn ($id) => new AnalyzeAdLibraryCreativeJob($id))->all())->name("Retry audit {$audit->id}")->onQueue('ad-library-analysis')->allowFailures()->dispatch();

        return response()->json($this->payload($audit), 202);
    }

    public function creatives(Request $request, AdLibraryAudit $audit)
    {
        $this->enabled();
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:24', 'review_state' => ['nullable', Rule::in(['pending', 'reviewed'])], 'errors' => 'nullable|boolean', 'persona_id' => 'nullable|integer', 'product' => 'nullable|string|max:100', 'angle' => 'nullable|string|max:100', 'format' => 'nullable|string|max:100', 'type' => 'nullable|string|max:100', 'media_type' => ['nullable', Rule::in(['static', 'video'])], 'per_page' => 'nullable|integer|min:1|max:100']);

        return $audit->creatives()->with('personas')
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($n) => $n->where('product', 'like', "%{$term}%")->orWhere('angle', 'like', "%{$term}%")->orWhere('format', 'like', "%{$term}%")->orWhere('type', 'like', "%{$term}%")))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('processing_status', $status))
            ->when($data['review_state'] ?? null, fn ($q, $status) => $q->where('review_status', $status))
            ->when(($data['errors'] ?? false), fn ($q) => $q->whereNotNull('error_message'))
            ->when($data['persona_id'] ?? null, fn ($q, $id) => $q->whereHas('personas', fn ($p) => $p->whereKey($id)))
            ->when($data['product'] ?? null, fn ($q, $value) => $q->where('product', 'like', "%{$value}%"))
            ->when($data['angle'] ?? null, fn ($q, $value) => $q->where('angle', 'like', "%{$value}%"))
            ->when($data['format'] ?? null, fn ($q, $value) => $q->where('format', 'like', "%{$value}%"))
            ->when($data['type'] ?? null, fn ($q, $value) => $q->where('type', 'like', "%{$value}%"))
            ->when($data['media_type'] ?? null, fn ($q, $type) => $q->where('source_snapshot->media_type', $type))
            ->paginate($data['per_page'] ?? 25);
    }

    public function updateCreative(Request $request, AdLibraryAudit $audit, AdLibraryAuditCreative $creative)
    {
        $this->manage($request, $audit);
        abort_unless(in_array($audit->status, ['creative_review', 'complete'], true), 409);
        $this->ownsCreative($audit, $creative);
        $data = $request->validate(['angle' => 'sometimes|nullable|string|max:5000', 'product' => 'sometimes|nullable|string|max:255', 'what_it_solves' => 'sometimes|nullable|string|max:5000', 'format' => 'sometimes|nullable|string|max:255', 'type' => 'sometimes|nullable|string|max:255', 'remarks' => 'sometimes|nullable|string|max:5000', 'primary_persona_id' => 'sometimes|required|integer', 'secondary_persona_ids' => 'sometimes|array|max:9', 'secondary_persona_ids.*' => 'integer|distinct', 'reviewed' => 'sometimes|boolean']);
        $fields = collect($data)->only(['angle', 'product', 'what_it_solves', 'format', 'type', 'remarks'])->all();
        $fields += ['manually_edited' => true, 'edited_by' => $request->user()->id, 'edited_at' => now()];
        if (array_key_exists('reviewed', $data)) {
            $fields += ['review_status' => $data['reviewed'] ? 'reviewed' : 'pending', 'reviewed_at' => $data['reviewed'] ? now() : null];
        }
        $creative->update($fields);
        if (isset($data['primary_persona_id'])) {
            $ids = array_unique(array_merge([$data['primary_persona_id']], $data['secondary_persona_ids'] ?? []));
            abort_unless($audit->personas()->whereIn('id', $ids)->count() === count($ids), 422);
            $sync = [$data['primary_persona_id'] => ['role' => 'primary']];
            foreach ($data['secondary_persona_ids'] ?? [] as $id) {
                if ($id !== $data['primary_persona_id']) {
                    $sync[$id] = ['role' => 'secondary'];
                }
            } $creative->personas()->sync($sync);
        }
        $audit->update(['grid_confirmed_at' => null, 'insights_stale' => $audit->insights !== null, 'status' => 'creative_review']);

        return $creative->fresh('personas');
    }

    public function confirmGrid(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless($audit->status === 'creative_review', 409);
        abort_if($audit->creatives()->where('processing_status', '!=', 'complete')->exists(), 422, 'Resolve or retry failed creatives before confirming the grid.');
        $audit->creatives()->where('review_status', '!=', 'reviewed')->update(['review_status' => 'reviewed', 'reviewed_at' => now()]);
        $audit->update(['grid_confirmed_at' => now(), 'status' => 'creative_review']);

        return $this->payload($audit);
    }

    public function generateInsights(Request $request, AdLibraryAudit $audit)
    {
        $this->manage($request, $audit);
        abort_unless(in_array($audit->status, ['creative_review', 'complete'], true) || ($audit->status === 'failed' && $audit->failed_stage === 'insights'), 409);
        abort_unless($audit->grid_confirmed_at, 422, 'Confirm the reviewed grid first.');
        $audit->update(['status' => 'generating_insights', 'error_message' => null, 'failed_stage' => null]);
        GenerateAdLibraryInsightsJob::dispatch($audit->id);

        return response()->json($this->payload($audit), 202);
    }

    public function exportCsv(Request $request, AdLibraryAudit $audit)
    {
        $this->enabled();
        $audit->load('creatives.personas');

        return response()->streamDownload(function () use ($audit) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Link', 'Persona', 'Secondary personas', 'Angle', 'Product', 'What it solves', 'Format', 'Type', 'Remarks', 'Confidence', 'Grouped ads']);
            foreach ($audit->creatives as $c) {
                $primary = $c->personas->firstWhere('pivot.role', 'primary');
                $secondary = $c->personas->filter(fn ($p) => $p->pivot->role === 'secondary')->pluck('name')->implode('; ');
                fputcsv($out, ['https://www.facebook.com/ads/library/?id='.($c->library_ad_ids[0] ?? ''), $primary?->name, $secondary, $c->angle, $c->product, $c->what_it_solves, $c->format, $c->type, $c->remarks, $c->confidence, count($c->library_ad_ids)]);
            } fclose($out);
        }, 'ad-library-audit-'.$audit->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportJson(Request $request, AdLibraryAudit $audit)
    {
        $this->enabled();

        return response()->json(['schema_version' => $audit->schema_version, 'audit' => $audit->load(['personas', 'creatives.personas', 'aiRequests'])->toArray()], 200, ['Content-Disposition' => 'attachment; filename="ad-library-audit-'.$audit->id.'.json"']);
    }

    private function payload(AdLibraryAudit $audit): array
    {
        return $audit->fresh()->load(['run.search:id,name,company_name,company_type,website_url', 'personas'])->loadCount(['creatives', 'personas'])->toArray();
    }

    private function manage(Request $request, ?AdLibraryAudit $audit = null): void
    {
        $this->enabled();
        abort_unless($request->user()->canManage(), 403, 'Read-only users cannot modify Ad Library audits.');
    }

    private function ownsPersona(AdLibraryAudit $audit, AdLibraryAuditPersona $persona): void
    {
        abort_unless($persona->ad_library_audit_id === $audit->id, 404);
    }

    private function ownsCreative(AdLibraryAudit $audit, AdLibraryAuditCreative $creative): void
    {
        abort_unless($creative->ad_library_audit_id === $audit->id, 404);
    }

    private function personaEditable(AdLibraryAudit $audit): void
    {
        abort_if(in_array($audit->status, ['collecting_context', 'generating_personas', 'analyzing_creatives', 'generating_insights'], true), 409, 'Wait for the current audit stage to finish before changing personas.');
    }

    private function personaData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|' : 'required|';

        return $request->validate(['name' => $required.'string|max:160', 'description' => $required.'string|max:5000', 'demographics' => 'nullable|string|max:1000', 'problems' => 'sometimes|array|max:30', 'desired_outcomes' => 'sometimes|array|max:30', 'motivations' => 'sometimes|array|max:30', 'purchase_triggers' => 'sometimes|array|max:30', 'objections' => 'sometimes|array|max:30', 'products' => 'sometimes|array|max:30', 'evidence' => 'sometimes|array|max:30', 'confidence' => 'nullable|numeric|min:0|max:1', 'position' => 'sometimes|integer|min:0|max:9']);
    }

    private function invalidateAfterPersonaChange(AdLibraryAudit $audit): void
    {
        if (! $audit->personas_confirmed_at && ! in_array($audit->status, ['analyzing_creatives', 'creative_review', 'generating_insights', 'complete'], true)) {
            if ($audit->status === 'failed' && $audit->failed_stage === 'personas') {
                $audit->update(['status' => 'persona_review', 'failed_stage' => null, 'error_message' => null]);
            }

            return;
        }
        DB::transaction(function () use ($audit) {
            DB::table('ad_library_audit_creative_persona')->whereIn('ad_library_audit_creative_id', $audit->creatives()->pluck('id'))->delete();
            $audit->personas()->update(['confirmed' => false]);
            $audit->creatives()->update(['processing_status' => 'pending', 'review_status' => 'pending', 'angle' => null, 'product' => null, 'what_it_solves' => null, 'format' => null, 'type' => null, 'remarks' => null, 'evidence' => null, 'confidence' => null, 'raw_ai_response' => null, 'reviewed_at' => null]);
            $audit->update(['status' => 'persona_review', 'personas_confirmed_at' => null, 'grid_confirmed_at' => null, 'insights' => null, 'insights_stale' => false, 'completed_at' => null, 'progress_completed' => 0, 'progress_failed' => 0]);
        });
    }

    private function groupedResults(AdLibraryRun $run)
    {
        return $run->results()->orderByRaw('rank IS NULL')->orderBy('rank')->get()->groupBy(fn ($row) => $this->fingerprint($row));
    }

    private function fingerprint(AdLibraryResult $row): string
    {
        $collation = $row->raw_payload['collation_id'] ?? null;
        if (is_string($collation) && $collation !== '') {
            return 'collation:'.$collation;
        }
        $videos = $row->video_urls ?? [];
        $media = count($videos) ? $videos : ($row->image_urls ?? []);
        $urls = collect($media)->filter(fn ($url) => is_string($url) && $url !== '')->map(function ($url) {
            $parts = parse_url($url);

            return strtolower($parts['host'] ?? '').($parts['path'] ?? $url);
        })->unique()->sort()->values()->all();
        $identity = ['media_type' => count($videos) ? 'video' : 'static', 'media' => $urls, 'advertiser_page_id' => $row->advertiser_page_id, 'display_format' => $row->display_format, 'body' => preg_replace('/\s+/u', ' ', trim($row->body ?? '')), 'title' => preg_replace('/\s+/u', ' ', trim($row->title ?? '')), 'link_title' => preg_replace('/\s+/u', ' ', trim($row->link_title ?? '')), 'link_description' => preg_replace('/\s+/u', ' ', trim($row->link_description ?? '')), 'cta_text' => trim($row->cta_text ?? '')];

        return $urls === [] && collect($identity)->except(['media_type', 'media'])->every(fn ($value) => blank($value)) ? 'ad:'.$row->library_ad_id : hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function enabled(): void
    {
        abort_unless(config('ad_library_audit.enabled') && config('services.ad_library.enabled'), 404);
    }

    private function companyTypes(): array
    {
        return ['client', 'competitor', 'lead', 'partner', 'other'];
    }
}
