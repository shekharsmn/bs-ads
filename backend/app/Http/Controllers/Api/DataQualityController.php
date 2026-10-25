<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataQualityIssue;
use App\Services\ClientAccess;
use Illuminate\Http\Request;

class DataQualityController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['client_id' => 'nullable|integer', 'status' => 'nullable|in:open,resolved', 'severity' => 'nullable|in:warning,critical', 'per_page' => 'nullable|integer|min:10|max:100']);
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');

        return DataQualityIssue::with(['client:id,name', 'adAccount:id,name,platform'])->whereIn('client_id', $clientIds)
            ->when($filters['client_id'] ?? null, fn ($q, $value) => $q->where('client_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['severity'] ?? null, fn ($q, $value) => $q->where('severity', $value))
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")->latest('last_detected_at')->paginate($filters['per_page'] ?? 30);
    }
}
