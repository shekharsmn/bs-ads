<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ReportTemplate;
use App\Services\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportTemplateController extends Controller
{
    public function index(Request $request)
    {
        $ids = ClientAccess::clientsFor($request->user())->pluck('id');

        return ReportTemplate::query()->where(fn ($query) => $query->whereNull('client_id')->orWhereIn('client_id', $ids))->with('client:id,name')->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);
        $data = $this->validated($request);
        if ($data['client_id'] ?? null) {
            ClientAccess::authorize($request->user(), Client::findOrFail($data['client_id']), true);
        } else {
            abort_unless($request->user()->isAdmin(), 403, 'Only administrators can create workspace-wide templates.');
        }

        return response()->json(ReportTemplate::create($data + ['created_by' => $request->user()->id]), 201);
    }

    public function update(Request $request, ReportTemplate $reportTemplate)
    {
        abort_unless($request->user()->canManage(), 403);
        if ($reportTemplate->client_id) {
            ClientAccess::authorize($request->user(), $reportTemplate->client_id, true);
        } else {
            abort_unless($request->user()->isAdmin(), 403);
        }
        $data = $this->validated($request, true);
        if (array_key_exists('client_id', $data) && $data['client_id']) {
            ClientAccess::authorize($request->user(), Client::findOrFail($data['client_id']), true);
        }
        if (array_key_exists('client_id', $data) && ! $data['client_id']) {
            abort_unless($request->user()->isAdmin(), 403, 'Only administrators can make a template workspace-wide.');
        }
        $reportTemplate->update($data);

        return $reportTemplate->fresh();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id', 'name' => ($partial ? 'sometimes|' : '').'required|string|max:120',
            'sections' => ($partial ? 'sometimes|' : '').'required|array|min:1',
            'sections.*' => ['string', 'distinct', Rule::in(['summary', 'trends', 'campaigns', 'alerts'])],
            'brand_name' => 'nullable|string|max:120', 'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => 'nullable|url:http,https|max:2000', 'is_default' => 'sometimes|boolean',
        ]);
    }
}
