<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBudgetPolicy;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiGovernanceController extends Controller
{
    public function summary()
    {
        $start = now()->startOfMonth();
        $legacy = DB::table('gemini_request_logs')->where('created_at', '>=', $start)->selectRaw("COALESCE(provider, 'gemini') provider, COALESCE(model, 'legacy') model, COUNT(*) requests, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failures, COALESCE(SUM(estimated_cost),0) cost, AVG(latency_ms) average_latency, SUM(CASE WHEN estimated_cost IS NULL THEN 1 ELSE 0 END) unpriced")->groupBy('provider', 'model')->get();
        $audits = DB::table('ad_library_audit_ai_requests')->where('created_at', '>=', $start)->selectRaw("provider, model, COUNT(*) requests, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failures, COALESCE(SUM(estimated_cost),0) cost, AVG(latency_ms) average_latency, 0 unpriced")->groupBy('provider', 'model')->get();
        $providers = $legacy->concat($audits)->groupBy(fn ($row) => $row->provider.':'.$row->model)->map(function ($rows) {
            $requests = $rows->sum('requests');

            return ['provider' => $rows->first()->provider, 'model' => $rows->first()->model, 'requests' => $requests, 'failures' => $rows->sum('failures'), 'failure_rate' => $requests ? round($rows->sum('failures') / $requests * 100, 1) : 0, 'estimated_cost' => round($rows->sum('cost'), 4), 'average_latency' => round($rows->avg('average_latency')), 'unpriced' => $rows->sum('unpriced')];
        })->values();
        $total = $providers->sum('estimated_cost');
        $budget = AiBudgetPolicy::whereNull('client_id')->first();

        return ['month' => $start->format('Y-m'), 'estimated_cost' => round($total, 4), 'budget' => $budget, 'budget_percent' => $budget && $budget->monthly_budget > 0 ? round($total / (float) $budget->monthly_budget * 100, 1) : null, 'providers' => $providers];
    }

    public function budgets()
    {
        return AiBudgetPolicy::with('client:id,name')->orderByRaw('client_id IS NOT NULL')->get();
    }

    public function saveBudget(Request $request, AuditTrail $audit)
    {
        $data = $request->validate(['client_id' => 'nullable|integer|exists:clients,id', 'monthly_budget' => 'required|numeric|min:0.01', 'warning_percent' => 'nullable|integer|min:1|max:99', 'critical_percent' => 'nullable|integer|min:2|max:100', 'enabled' => 'nullable|boolean']);
        $policy = AiBudgetPolicy::where('client_id', $data['client_id'] ?? null)->first();
        $before = $policy?->toArray() ?? [];
        $policy ??= new AiBudgetPolicy(['client_id' => $data['client_id'] ?? null]);
        $policy->fill([...$data, 'updated_by' => $request->user()->id])->save();
        $audit->record('ai.budget_updated', $policy, $before, $policy->fresh()->toArray(), $policy->client_id, $request->user(), $request);

        return $policy->fresh('client:id,name');
    }
}
