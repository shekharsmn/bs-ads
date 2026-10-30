<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertOperator;
use App\Enums\CanonicalMetric;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Services\ClientAccess;
use App\Services\ClientScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class AlertRuleController extends Controller
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    public function templates(Request $request)
    {
        return AlertTemplate::query()->orderBy('name')->get();
    }

    public function storeTemplate(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(AlertTemplate::create($this->validateRule($request, true)), 201);
    }

    public function updateTemplate(Request $request, AlertTemplate $alertTemplate)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $alertTemplate->update($this->validateRule($request, true, false, $alertTemplate->id));

        return $alertTemplate->fresh();
    }

    public function destroyTemplate(Request $request, AlertTemplate $alertTemplate)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $alertTemplate->delete();

        return response()->noContent();
    }

    public function index(Request $request)
    {
        $data = $request->validate(['client_id' => 'nullable|exists:clients,id', 'ad_account_id' => 'nullable|exists:ad_accounts,id']);
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        if (($data['client_id'] ?? null) || ($data['ad_account_id'] ?? null)) {
            $this->scopes->resolve($request->user(), $data);
        }

        return AlertRule::query()->where(function ($q) use ($clientIds) {
            $q->whereNull('client_id')->orWhereIn('client_id', $clientIds);
        })->when($data['client_id'] ?? null, fn ($q, $id) => $q->where(fn ($sq) => $sq->whereNull('client_id')->orWhere('client_id', $id)))
            ->when($data['ad_account_id'] ?? null, fn ($q, $id) => $q->where(fn ($scope) => $scope->whereNull('ad_account_id')->orWhere('ad_account_id', $id)))
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
        $this->authorizeScope($request, array_merge($alertRule->only(['client_id', 'ad_account_id', 'campaign_id']), $data));
        $alertRule->update($data);

        return $alertRule->fresh();
    }

    public function destroy(Request $request, AlertRule $alertRule)
    {
        $this->authorizeExisting($request, $alertRule);
        abort_if(
            ! $alertRule->client_id && ! $alertRule->ad_account_id && ! $alertRule->campaign_id,
            422,
            'Workspace-wide policies cannot be deleted through client-scoped policy management.'
        );
        $alertRule->delete();

        return response()->noContent();
    }

    private function validateRule(Request $request, bool $template = false, bool $partial = false, ?int $ignoreTemplateId = null): array
    {
        $sometimes = $partial ? 'sometimes|' : '';
        $base = [
            'name' => $sometimes.'required|string|max:255', 'metric' => [$partial ? 'sometimes' : 'required', new Enum(CanonicalMetric::class)],
            'operator' => [$partial ? 'sometimes' : 'required', new Enum(AlertOperator::class)], 'threshold' => 'nullable|numeric',
            'severity' => [$partial ? 'sometimes' : 'required', new Enum(Severity::class)], 'config' => 'nullable|array',
        ];
        if ($template) {
            $uniqueKey = 'unique:alert_templates,key'.($ignoreTemplateId ? ','.$ignoreTemplateId : '');

            return $request->validate($base + ['key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/', $uniqueKey], 'description' => 'nullable|string', 'active' => 'sometimes|boolean']);
        }

        return $request->validate($base + [
            'alert_template_id' => 'nullable|exists:alert_templates,id', 'client_id' => 'nullable|exists:clients,id',
            'ad_account_id' => 'nullable|exists:ad_accounts,id', 'campaign_id' => 'nullable|exists:campaigns,id',
            'window_days' => 'sometimes|integer|min:1|max:90', 'minimum_spend' => 'nullable|numeric|min:0',
            'minimum_impressions' => 'nullable|integer|min:0', 'enabled' => 'sometimes|boolean',
        ]);
    }

    private function authorizeScope(Request $request, array $data): void
    {
        $this->scopes->resolve($request->user(), $data, manage: true, allowGlobal: true);
    }

    private function authorizeExisting(Request $request, AlertRule $rule): void
    {
        if (! $rule->client_id && ! $rule->ad_account_id && ! $rule->campaign_id) {
            abort_unless($request->user()->isAdmin(), 403);
        } else {
            $this->authorizeScope($request, $rule->toArray());
        }
    }
}
