<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Client;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Services\ClientAccess;
use Illuminate\Http\Request;

class ReportScheduleController extends Controller
{
    public function index(Request $request)
    {
        $ids = ClientAccess::clientsFor($request->user())->pluck('id');
        return ['schedules' => ReportSchedule::whereIn('client_id', $ids)->with('client:id,name')->get(), 'runs' => ReportRun::whereIn('client_id', $ids)->with('client:id,name')->latest()->limit(100)->get()->map(fn ($r) => $this->runPayload($r))];
    }

    public function store(Request $request)
    {
        $data = $this->validateSchedule($request);
        ClientAccess::authorize($request->user(), Client::findOrFail($data['client_id']), true);
        return response()->json(ReportSchedule::updateOrCreate(['client_id' => $data['client_id'], 'frequency' => $data['frequency']], $data), 201);
    }

    public function update(Request $request, ReportSchedule $reportSchedule)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($reportSchedule->client_id), true);
        $reportSchedule->update($this->validateSchedule($request, true));
        return $reportSchedule->fresh();
    }

    public function generate(Request $request, Client $client)
    {
        ClientAccess::authorize($request->user(), $client, true);
        $data = $request->validate(['frequency' => 'required|in:weekly,monthly', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start']);
        $run = ReportRun::create($data + ['client_id' => $client->id, 'status' => 'pending']);
        GenerateReportSnapshotJob::dispatch($run->id);
        return response()->json($this->runPayload($run), 202);
    }

    private function validateSchedule(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'client_id' => ($partial ? 'sometimes|' : '').'required|exists:clients,id',
            'frequency' => ($partial ? 'sometimes|' : '').'required|in:weekly,monthly',
            'weekday' => 'nullable|integer|min:0|max:6', 'day_of_month' => 'nullable|integer|min:1|max:28',
            'local_time' => 'sometimes|date_format:H:i', 'enabled' => 'sometimes|boolean',
        ]);
    }

    private function runPayload(ReportRun $run): array
    {
        return array_merge($run->makeVisible('public_token')->toArray(), ['share_url' => $run->public_token ? rtrim(config('app.frontend_url'), '/').'/shared/reports/'.$run->public_token : null]);
    }
}
