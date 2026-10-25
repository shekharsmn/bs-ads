<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Client;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Services\ClientAccess;
use App\Services\ClientScopeResolver;
use App\Services\MethodologyRegistry;
use Illuminate\Http\Request;

class ReportScheduleController extends Controller
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    public function index(Request $request)
    {
        $ids = ClientAccess::clientsFor($request->user())->pluck('id');

        return ['schedules' => ReportSchedule::whereIn('client_id', $ids)->with('client:id,name')->get(), 'runs' => ReportRun::whereIn('client_id', $ids)->with('client:id,name')->latest()->limit(100)->get()->map(fn ($r) => $this->runPayload($r))];
    }

    public function store(Request $request)
    {
        $data = $this->validateSchedule($request);
        $this->scopes->resolve($request->user(), $data, manage: true);
        $this->validateTemplateForClient($data['report_template_id'] ?? null, (int) $data['client_id']);

        return response()->json(ReportSchedule::updateOrCreate(['client_id' => $data['client_id'], 'frequency' => $data['frequency']], $data), 201);
    }

    public function update(Request $request, ReportSchedule $reportSchedule)
    {
        ClientAccess::authorize($request->user(), Client::findOrFail($reportSchedule->client_id), true);
        $data = $this->validateSchedule($request, true);
        $targetClientId = (int) ($data['client_id'] ?? $reportSchedule->client_id);
        $this->scopes->resolve($request->user(), ['client_id' => $targetClientId], manage: true);
        $this->validateTemplateForClient($data['report_template_id'] ?? null, $targetClientId);
        $reportSchedule->update($data);

        return $reportSchedule->fresh();
    }

    public function generate(Request $request, Client $client, MethodologyRegistry $methodologies)
    {
        ClientAccess::authorize($request->user(), $client, true);
        $data = $request->validate([
            'frequency' => 'nullable|in:instant,weekly,monthly',
            'preset' => 'nullable|in:last_7d,last_30d,month_to_date',
            'period_start' => 'required_without:preset|date',
            'period_end' => 'required_without:preset|date|after_or_equal:period_start', 'report_template_id' => 'nullable|integer|exists:report_templates,id',
        ]);

        if ($data['preset'] ?? null) {
            $today = now($client->timezone)->startOfDay();
            $data['period_start'] = match ($data['preset']) {
                'last_7d' => $today->copy()->subDays(6)->toDateString(),
                'month_to_date' => $today->copy()->startOfMonth()->toDateString(),
                default => $today->copy()->subDays(29)->toDateString(),
            };
            $data['period_end'] = $today->toDateString();
        }

        if ($data['report_template_id'] ?? null) {
            abort_unless(ReportTemplate::query()->whereKey($data['report_template_id'])->where(fn ($query) => $query->whereNull('client_id')->orWhere('client_id', $client->id))->exists(), 422, 'The selected template is not available for this client.');
        }

        unset($data['preset']);
        $data['frequency'] = $data['frequency'] ?? 'instant';
        $run = ReportRun::create($data + ['client_id' => $client->id, 'status' => 'pending'] + $methodologies->columns('performance-report'));
        GenerateReportSnapshotJob::dispatch($run->id);

        return response()->json($this->runPayload($run), 202);
    }

    private function validateSchedule(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'client_id' => ($partial ? 'sometimes|' : '').'required|exists:clients,id',
            'frequency' => ($partial ? 'sometimes|' : '').'required|in:weekly,monthly',
            'weekday' => 'nullable|integer|min:0|max:6', 'day_of_month' => 'nullable|integer|min:1|max:28',
            'local_time' => 'sometimes|date_format:H:i', 'enabled' => 'sometimes|boolean', 'report_template_id' => 'nullable|integer|exists:report_templates,id',
        ]);
    }

    private function runPayload(ReportRun $run): array
    {
        return array_merge($run->makeVisible('public_token')->toArray(), ['share_url' => $run->public_token ? rtrim(config('app.frontend_url'), '/').'/shared/reports/'.$run->public_token : null]);
    }

    private function validateTemplateForClient(?int $templateId, int $clientId): void
    {
        if (! $templateId) {
            return;
        }
        abort_unless(ReportTemplate::query()->whereKey($templateId)->where(fn ($query) => $query->whereNull('client_id')->orWhere('client_id', $clientId))->exists(), 422, 'The selected template is not available for this client.');
    }
}
