<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertIncident;
use App\Models\Client;
use App\Services\ClientAccess;
use App\Services\IncidentService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'nullable|exists:clients,id', 'ad_account_id' => 'nullable|exists:ad_accounts,id', 'status' => 'nullable|in:open,acknowledged,snoozed,resolved',
            'severity' => 'nullable|in:info,warning,critical', 'assigned_to' => 'nullable|integer|exists:users,id',
            'q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        $ids = ClientAccess::clientsFor($request->user())->pluck('id');

        return AlertIncident::query()->whereIn('client_id', $ids)
            ->when($data['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($data['ad_account_id'] ?? null, fn ($q, $v) => $q->where('ad_account_id', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($data['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($nested) => $nested->where('title', 'like', "%{$v}%")->orWhere('summary', 'like', "%{$v}%")))
            ->with(['client:id,name,slug', 'adAccount:id,name,platform,currency,timezone', 'campaign:id,name', 'adGroup:id,name,campaign_id', 'ad:id,name,ad_group_id', 'assignee:id,name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")->orderByDesc('triggered_at')->paginate($data['per_page'] ?? 50);
    }

    public function show(Request $request, AlertIncident $alert)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($alert->client_id));

        return $alert->load(['client:id,name,slug', 'rule', 'adAccount', 'campaign', 'adGroup', 'ad', 'assignee:id,name,email', 'events.actor:id,name']);
    }

    public function action(Request $request, AlertIncident $alert, string $action, IncidentService $incidents)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($alert->client_id), true);
        abort_unless(in_array($action, ['acknowledge', 'assign', 'note', 'snooze', 'resolve'], true), 404);
        $rules = ['note' => 'nullable|string|max:2000'];
        if ($action === 'assign') {
            $rules['assigned_to'] = 'required|exists:users,id';
        }
        if ($action === 'snooze') {
            $rules['snoozed_until'] = 'required|date|after:now';
        }

        return $incidents->transition($alert, $action, $request->user(), $request->validate($rules));
    }

    public function bulkAction(Request $request, IncidentService $incidents)
    {
        abort_unless($request->user()->canManage(), 403);
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'uuid|exists:alert_incidents,id',
            'action' => 'required|in:acknowledge,snooze,resolve', 'note' => 'nullable|string|max:2000',
            'snoozed_until' => 'required_if:action,snooze|nullable|date|after:now',
        ]);
        $allowedClientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        $alerts = AlertIncident::query()->whereIn('id', $data['ids'])->whereIn('client_id', $allowedClientIds)->get();
        abort_unless($alerts->count() === count(array_unique($data['ids'])), 403);
        $alerts->each(fn (AlertIncident $alert) => $incidents->transition($alert, $data['action'], $request->user(), $data));

        return response()->json(['updated' => $alerts->count()]);
    }
}
