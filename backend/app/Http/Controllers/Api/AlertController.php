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
        $data = $request->validate(['client_id' => 'nullable|exists:clients,id', 'status' => 'nullable|in:open,acknowledged,snoozed,resolved', 'severity' => 'nullable|in:info,warning,critical']);
        $ids = ClientAccess::clientsFor($request->user())->pluck('id');
        return AlertIncident::query()->whereIn('client_id', $ids)
            ->when($data['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->with(['client:id,name,slug', 'adAccount:id,name,platform', 'campaign:id,name', 'assignee:id,name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")->orderByDesc('triggered_at')->paginate(50);
    }

    public function show(Request $request, AlertIncident $alert)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($alert->client_id));
        return $alert->load(['client:id,name', 'rule', 'adAccount', 'campaign', 'assignee:id,name,email', 'events.actor:id,name']);
    }

    public function action(Request $request, AlertIncident $alert, string $action, IncidentService $incidents)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($alert->client_id), true);
        abort_unless(in_array($action, ['acknowledge', 'assign', 'note', 'snooze', 'resolve'], true), 404);
        $rules = ['note' => 'nullable|string|max:2000'];
        if ($action === 'assign') $rules['assigned_to'] = 'required|exists:users,id';
        if ($action === 'snooze') $rules['snoozed_until'] = 'required|date|after:now';
        return $incidents->transition($alert, $action, $request->user(), $request->validate($rules));
    }
}
