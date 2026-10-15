<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertOperator;
use App\Enums\CanonicalMetric;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Campaign;
use App\Models\Client;
use App\Services\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class AlertRuleController extends Controller
{
    public function templates(Request $request)
    {
        return AlertTemplate::query()->orderBy('name')->get();
    }

    public function storeTemplate(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        return response()->json(AlertTemplate::create($this->validateRule($request, true)), 201);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['client_id' => 'nullable|exists:clients,id']);
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        return AlertRule::query()->where(function ($q) use ($clientIds) {
            $q->whereNull('client_id')->orWhereIn('client_id', $clientIds);
        })->when($data['client_id'] ?? null, fn ($q, $id) => $q->where(fn ($sq) => $sq->whereNull('client_id')->orWhere('client_id', $id)))
            ->with(['template:id,key,name', 'client:id,name', 'adAccount:id,name', 'campaign:id,name'])->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateRule($request);
        $this->authorizeScope($request, $data);
        return response()->json(AlertRule::create($data), 201);
    }

    public function update(Request $request, AlertRule $alertRule)
    {
        $this->authorizeExisting($request, $alertRule);
        $data = $this->validateRule($request, false, true);
        $alertRule->update($data);
        return $alertRule->fresh();
    }

    public function destroy(Request $request, AlertRule $alertRule)
    {
        $this->authorizeExisting($request, $alertRule);
        $alertRule->delete();
        return response()->noContent();
    }

    private function validateRule(Request $request, bool $template = false, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes|' : '';
        $base = [
            'name' => $sometimes.'required|string|max:255', 'metric' => [$partial ? 'sometimes' : 'required', new Enum(CanonicalMetric::class)],
            'operator' => [$partial ? 'sometimes' : 'required', new Enum(AlertOperator::class)], 'threshold' => 'nullable|numeric',
            'severity' => [$partial ? 'sometimes' : 'required', new Enum(Severity::class)], 'config' => 'nullable|array',
        ];
        if ($template) return $request->validate($base + ['key' => 'required|string|max:100|unique:alert_templates,key', 'description' => 'nullable|string', 'active' => 'sometimes|boolean']);
        return $request->validate($base + [
            'alert_template_id' => 'nullable|exists:alert_templates,id', 'client_id' => 'nullable|exists:clients,id',
            'ad_account_id' => 'nullable|exists:ad_accounts,id', 'campaign_id' => 'nullable|exists:campaigns,id',
            'window_days' => 'sometimes|integer|min:1|max:90', 'minimum_spend' => 'nullable|numeric|min:0',
            'minimum_impressions' => 'nullable|integer|min:0', 'enabled' => 'sometimes|boolean',
        ]);
    }

    private function authorizeScope(Request $request, array $data): void
    {
        if (! ($data['client_id'] ?? null) && ! ($data['ad_account_id'] ?? null) && ! ($data['campaign_id'] ?? null)) abort_unless($request->user()->isAdmin(), 403);
        $clientId = $data['client_id'] ?? null;
        if ($data['ad_account_id'] ?? null) $clientId = AdAccount::findOrFail($data['ad_account_id'])->client_id;
        if ($data['campaign_id'] ?? null) $clientId = Campaign::findOrFail($data['campaign_id'])->adAccount->client_id;
        if ($clientId) ClientAccess::authorize($request->user(), Client::findOrFail($clientId), true);
    }

    private function authorizeExisting(Request $request, AlertRule $rule): void
    {
        if (! $rule->client_id && ! $rule->ad_account_id && ! $rule->campaign_id) abort_unless($request->user()->isAdmin(), 403);
        else $this->authorizeScope($request, $rule->toArray());
    }
}
