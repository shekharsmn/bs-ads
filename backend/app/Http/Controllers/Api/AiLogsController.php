<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiLogsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'nullable|integer|exists:meta_accounts,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'source' => 'nullable|in:report,content_audit',
            'status' => 'nullable|in:running,complete,failed',
            'per_page' => 'nullable|integer|min:10|max:200',
            'view' => 'nullable|in:requests,grouped',
        ]);

        $filters = [
            'account_id' => $validated['account_id'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'source' => $validated['source'] ?? null,
            'status' => $validated['status'] ?? null,
        ];

        if (($validated['view'] ?? 'requests') === 'grouped') {
            return $this->grouped($filters, $validated['per_page'] ?? 20);
        }

        $logs = $this->filteredRequests($filters)->orderByDesc('created_at')->orderByDesc('id')->paginate($validated['per_page'] ?? 50);

        return response()->json(['data' => $logs->items(), 'meta' => $this->paginationMeta($logs)]);
    }

    private function grouped(array $filters, int $perPage)
    {
        $summary = $this->filteredRequests($filters)
            ->selectRaw("COUNT(*) as request_count, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(estimated_cost), 0) as estimated_cost, SUM(CASE WHEN cost_basis = 'current_catalog' THEN 1 ELSE 0 END) as estimated_request_count, SUM(CASE WHEN cost_basis = 'unpriced' THEN 1 ELSE 0 END) as unpriced_request_count")
            ->first();

        $groups = $this->filteredRequests($filters)
            ->select([
                'log_source', 'report_id', 'audit_id', 'meta_account_id', 'scope_name', 'group_name',
                DB::raw('COUNT(*) as request_count'),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count"),
                DB::raw('COALESCE(SUM(input_tokens), 0) as input_tokens'),
                DB::raw('COALESCE(SUM(output_tokens), 0) as output_tokens'),
                DB::raw('COALESCE(SUM(estimated_cost), 0) as estimated_cost'),
                DB::raw("SUM(CASE WHEN cost_basis = 'current_catalog' THEN 1 ELSE 0 END) as estimated_request_count"),
                DB::raw("SUM(CASE WHEN cost_basis = 'unpriced' THEN 1 ELSE 0 END) as unpriced_request_count"),
                DB::raw('MIN(created_at) as first_request_at'), DB::raw('MAX(created_at) as latest_request_at'),
            ])
            ->groupBy('log_source', 'report_id', 'audit_id', 'meta_account_id', 'scope_name', 'group_name')
            ->orderByDesc('latest_request_at')->paginate($perPage);

        $groupItems = collect($groups->items());
        $requestsByGroup = collect();
        if ($groupItems->isNotEmpty()) {
            $requestsByGroup = $this->filteredRequests($filters)
                ->where(function (Builder $query) use ($groupItems) {
                    foreach ($groupItems as $group) {
                        $query->orWhere(function (Builder $groupQuery) use ($group) {
                            $groupQuery->where('log_source', $group->log_source)
                                ->where($group->audit_id ? 'audit_id' : 'report_id', $group->audit_id ?: $group->report_id);
                        });
                    }
                })
                ->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy(fn ($row) => $this->groupKey($row));
        }

        $data = $groupItems->map(function ($group) use ($requestsByGroup) {
            $group->group_id = $this->groupKey($group);
            $group->requests = $requestsByGroup->get($group->group_id, collect())->values()->all();

            return $group;
        })->all();

        return response()->json(['data' => $data, 'summary' => $summary, 'meta' => $this->paginationMeta($groups)]);
    }

    private function filteredRequests(array $filters): Builder
    {
        return DB::query()->fromSub($this->requests($filters['account_id'], $filters['date_from'], $filters['date_to']), 'ai_requests')
            ->when($filters['source'], fn (Builder $query, string $source) => $query->where('log_source', $source))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status));
    }

    private function requests(mixed $accountId, mixed $dateFrom, mixed $dateTo): Builder
    {
        $legacyCost = 'CASE WHEN gemini_request_logs.estimated_cost IS NOT NULL THEN gemini_request_logs.estimated_cost WHEN report_models.id IS NOT NULL THEN ((gemini_request_logs.input_tokens * report_models.input_price) + (gemini_request_logs.output_tokens * report_models.output_price)) / 1000000 ELSE NULL END';
        $legacyBasis = "CASE WHEN gemini_request_logs.estimated_cost IS NOT NULL THEN 'recorded' WHEN report_models.id IS NOT NULL THEN 'current_catalog' ELSE 'unpriced' END";

        $legacy = DB::table('gemini_request_logs')
            ->leftJoin('meta_accounts', 'meta_accounts.id', '=', 'gemini_request_logs.meta_account_id')
            ->leftJoin('ai_models as report_models', fn ($join) => $join->where('report_models.provider', 'gemini')->where('report_models.model', 'gemini-2.5-flash-lite'))
            ->when($accountId, fn (Builder $query, int $id) => $query->where('gemini_request_logs.meta_account_id', $id))
            ->when($dateFrom, fn (Builder $query, string $date) => $query->whereDate('gemini_request_logs.created_at', '>=', $date))
            ->when($dateTo, fn (Builder $query, string $date) => $query->whereDate('gemini_request_logs.created_at', '<=', $date))
            ->select([
                'gemini_request_logs.id', DB::raw("'report' as log_source"), 'gemini_request_logs.report_id', DB::raw('NULL as audit_id'),
                'gemini_request_logs.meta_account_id', DB::raw('COALESCE(meta_accounts.label, meta_accounts.account_name) as scope_name'), DB::raw('NULL as group_name'),
                'gemini_request_logs.ad_name as request_name', 'gemini_request_logs.call_type', DB::raw("COALESCE(gemini_request_logs.provider, 'gemini') as provider"),
                DB::raw("COALESCE(gemini_request_logs.model, 'gemini-2.5-flash-lite') as model"), DB::raw("COALESCE(gemini_request_logs.status, 'complete') as status"),
                'gemini_request_logs.input_tokens', 'gemini_request_logs.output_tokens', DB::raw("{$legacyCost} as estimated_cost"), DB::raw("{$legacyBasis} as cost_basis"),
                'gemini_request_logs.latency_ms', 'gemini_request_logs.error_message', 'gemini_request_logs.created_at',
            ]);

        $audits = DB::table('ad_library_audit_ai_requests')
            ->join('ad_library_audits', 'ad_library_audits.id', '=', 'ad_library_audit_ai_requests.ad_library_audit_id')
            ->when($accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($dateFrom, fn (Builder $query, string $date) => $query->whereDate('ad_library_audit_ai_requests.created_at', '>=', $date))
            ->when($dateTo, fn (Builder $query, string $date) => $query->whereDate('ad_library_audit_ai_requests.created_at', '<=', $date))
            ->select([
                'ad_library_audit_ai_requests.id', DB::raw("'content_audit' as log_source"), DB::raw('NULL as report_id'), 'ad_library_audit_ai_requests.ad_library_audit_id as audit_id',
                DB::raw('NULL as meta_account_id'), 'ad_library_audits.company_name as scope_name', 'ad_library_audits.name as group_name', 'ad_library_audits.name as request_name',
                'ad_library_audit_ai_requests.stage as call_type', 'ad_library_audit_ai_requests.provider', 'ad_library_audit_ai_requests.model', 'ad_library_audit_ai_requests.status',
                'ad_library_audit_ai_requests.input_tokens', 'ad_library_audit_ai_requests.output_tokens', 'ad_library_audit_ai_requests.estimated_cost', DB::raw("'recorded' as cost_basis"),
                'ad_library_audit_ai_requests.latency_ms', 'ad_library_audit_ai_requests.error_message', 'ad_library_audit_ai_requests.created_at',
            ]);

        return $legacy->unionAll($audits);
    }

    private function groupKey(object $row): string
    {
        return $row->log_source.':'.($row->audit_id ?: $row->report_id);
    }

    private function paginationMeta($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'from' => $paginator->firstItem(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()];
    }
}
