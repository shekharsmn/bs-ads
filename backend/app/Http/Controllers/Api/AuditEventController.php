<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['actor_id' => 'nullable|integer', 'client_id' => 'nullable|integer', 'action' => 'nullable|string|max:100', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from', 'per_page' => 'nullable|integer|min:10|max:100']);
        $query = AuditEvent::with(['actor:id,name,email', 'client:id,name'])
            ->when($filters['actor_id'] ?? null, fn ($q, $value) => $q->where('actor_id', $value))
            ->when($filters['client_id'] ?? null, fn ($q, $value) => $q->where('client_id', $value))
            ->when($filters['action'] ?? null, fn ($q, $value) => $q->where('action', 'like', "%{$value}%"))
            ->when($filters['date_from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))->latest('id');

        return $query->paginate($filters['per_page'] ?? 50);
    }

    public function export(Request $request)
    {
        $rows = AuditEvent::with(['actor:id,name,email', 'client:id,name'])->latest('id')->limit(10000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'actor', 'client', 'action', 'entity_type', 'entity_id', 'correlation_id']);
            foreach ($rows as $row) {
                fputcsv($out, [$row->created_at, $row->actor?->email, $row->client?->name, $row->action, $row->entity_type, $row->entity_id, $row->correlation_id]);
            }
            fclose($out);
        }, 'audit-events.csv', ['Content-Type' => 'text/csv']);
    }
}
