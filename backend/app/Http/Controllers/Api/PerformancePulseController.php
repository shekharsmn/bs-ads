<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GeneratePerformancePulseJob;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\NotificationDestination;
use App\Models\PerformancePulse;
use App\Models\PerformancePulseRun;
use App\Services\AuditTrail;
use App\Services\ClientAccess;
use App\Services\ClientScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerformancePulseController extends Controller
{
    private const PERIODS = ['yesterday', 'last_7_days', 'month_to_date', 'last_30_days'];

    public function __construct(private readonly ClientScopeResolver $scopes) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id', 'ad_account_id' => 'nullable|integer|exists:ad_accounts,id',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
        ]);
        if ($filters) {
            $this->scopes->resolve($request->user(), $filters);
        }
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');

        return PerformancePulse::query()->whereIn('client_id', $clientIds)
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['ad_account_id'] ?? null, fn ($query, $id) => $query->where('ad_account_id', $id))
            ->when($filters['campaign_id'] ?? null, fn ($query, $id) => $query->where('campaign_id', $id))
            ->with(['client:id,name,objective', 'adAccount:id,client_id,name,platform,currency,timezone', 'campaign:id,ad_account_id,name', 'destinations:id,client_id,name,channel,enabled'])
            ->with(['runs' => fn ($query) => $query->latest()->limit(10)->with(['triggeredBy:id,name', 'deliveries.destination:id,name,channel'])])
            ->latest()->get();
    }

    public function options(Request $request)
    {
        $data = $request->validate(['client_id' => 'required|integer|exists:clients,id', 'ad_account_id' => 'nullable|integer|exists:ad_accounts,id']);
        $scope = $this->scopes->resolve($request->user(), $data);

        return [
            'destinations' => NotificationDestination::query()->where('client_id', $scope->client->id)->where('enabled', true)->orderByRaw("channel = 'google_chat' desc")->orderBy('name')->get(['id', 'name', 'channel', 'enabled']),
            'campaigns' => $scope->adAccount ? Campaign::query()->where('ad_account_id', $scope->adAccount->id)->orderBy('name')->get(['id', 'ad_account_id', 'name', 'status', 'type']) : [],
            'legacy_google_chat_configured' => $scope->client->google_chat_webhook_configured,
        ];
    }

    public function store(Request $request, AuditTrail $audit)
    {
        $data = $this->validated($request);
        $scope = $this->scopes->resolve($request->user(), $data, manage: true);
        $this->ensureUniqueScope($scope->client->id, $scope->adAccount->id, $scope->campaign?->id);
        $destinationIds = $this->destinationIds($scope->client, $data['destination_ids'] ?? null, $request);
        $this->ensureEnabledHasDestination($data['enabled'] ?? false, $destinationIds);
        $pulse = DB::transaction(function () use ($data, $scope, $destinationIds, $request) {
            $pulse = PerformancePulse::create([
                ...collect($data)->except('destination_ids')->all(),
                'client_id' => $scope->client->id, 'ad_account_id' => $scope->adAccount->id, 'campaign_id' => $scope->campaign?->id,
                'scope_key' => $scope->campaign ? 'campaign:'.$scope->campaign->id : 'account:'.$scope->adAccount->id,
                'enabled' => $data['enabled'] ?? false, 'created_by' => $request->user()->id,
            ]);
            $pulse->destinations()->sync($destinationIds);

            return $pulse;
        });
        $audit->record('performance_pulse.created', $pulse, [], $this->auditValues($pulse), $pulse->client_id, $request->user(), $request);

        return response()->json($this->payload($pulse), 201);
    }

    public function update(Request $request, PerformancePulse $performancePulse, AuditTrail $audit)
    {
        ClientAccess::authorize($request->user(), $performancePulse->client_id, true);
        $data = $this->validated($request, true, $performancePulse);
        $scopeData = [
            'client_id' => $performancePulse->client_id,
            'ad_account_id' => $data['ad_account_id'] ?? $performancePulse->ad_account_id,
            'campaign_id' => array_key_exists('campaign_id', $data) ? $data['campaign_id'] : $performancePulse->campaign_id,
        ];
        $scope = $this->scopes->resolve($request->user(), $scopeData, manage: true);
        $this->ensureUniqueScope($scope->client->id, $scope->adAccount->id, $scope->campaign?->id, $performancePulse->id);
        $destinationIds = array_key_exists('destination_ids', $data)
            ? $this->destinationIds($scope->client, $data['destination_ids'], $request)
            : $performancePulse->destinations()->pluck('notification_destinations.id')->all();
        $this->ensureEnabledHasDestination($data['enabled'] ?? $performancePulse->enabled, $destinationIds);
        $before = $this->auditValues($performancePulse);
        DB::transaction(function () use ($data, $scope, $performancePulse, $destinationIds) {
            $performancePulse->update([
                ...collect($data)->except('destination_ids')->all(),
                'ad_account_id' => $scope->adAccount->id, 'campaign_id' => $scope->campaign?->id,
                'scope_key' => $scope->campaign ? 'campaign:'.$scope->campaign->id : 'account:'.$scope->adAccount->id,
            ]);
            if (array_key_exists('destination_ids', $data)) {
                $performancePulse->destinations()->sync($destinationIds);
            }
        });
        $audit->record('performance_pulse.updated', $performancePulse, $before, $this->auditValues($performancePulse->fresh()), $performancePulse->client_id, $request->user(), $request);

        return $this->payload($performancePulse->fresh());
    }

    public function destroy(Request $request, PerformancePulse $performancePulse, AuditTrail $audit)
    {
        ClientAccess::authorize($request->user(), $performancePulse->client_id, true);
        $audit->record('performance_pulse.deleted', $performancePulse, $this->auditValues($performancePulse), [], $performancePulse->client_id, $request->user(), $request);
        $performancePulse->delete();

        return response()->noContent();
    }

    public function send(Request $request, PerformancePulse $performancePulse, AuditTrail $audit)
    {
        ClientAccess::authorize($request->user(), $performancePulse->client_id, true);
        abort_if($performancePulse->destinations()->doesntExist(), 422, 'Choose at least one notification destination.');
        $reportingDate = now($performancePulse->adAccount->timezone)->subDay()->toDateString();
        $run = PerformancePulseRun::create([
            'performance_pulse_id' => $performancePulse->id, 'triggered_by' => $request->user()->id, 'trigger' => 'manual',
            'reporting_date' => $reportingDate, 'idempotency_key' => (string) Str::uuid(), 'status' => 'pending',
        ]);
        GeneratePerformancePulseJob::dispatch($run->id);
        $audit->record('performance_pulse.sent_manually', $performancePulse, [], ['run_id' => $run->id], $performancePulse->client_id, $request->user(), $request);

        return response()->json($run, 202);
    }

    public function runs(Request $request, PerformancePulse $performancePulse)
    {
        ClientAccess::authorize($request->user(), $performancePulse->client_id);

        return $performancePulse->runs()->with(['triggeredBy:id,name', 'deliveries.destination:id,name,channel'])->latest()->paginate(50);
    }

    private function validated(Request $request, bool $partial = false, ?PerformancePulse $pulse = null): array
    {
        $required = $partial ? 'sometimes|' : '';

        return $request->validate([
            'client_id' => $partial ? 'prohibited' : 'required|integer|exists:clients,id',
            'ad_account_id' => $required.'required|integer|exists:ad_accounts,id',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
            'periods' => $required.'required|array|size:2',
            'periods.*' => ['required', 'distinct', Rule::in(self::PERIODS)],
            'local_time' => $required.'required|date_format:H:i',
            'include_campaign_breakdown' => 'sometimes|boolean', 'enabled' => 'sometimes|boolean',
            'destination_ids' => 'sometimes|array', 'destination_ids.*' => 'integer|distinct|exists:notification_destinations,id',
        ]);
    }

    private function destinationIds(Client $client, ?array $ids, Request $request): array
    {
        if ($ids !== null) {
            $count = NotificationDestination::where('client_id', $client->id)->where('enabled', true)->whereIn('id', $ids)->count();
            if ($count !== count($ids)) {
                throw ValidationException::withMessages(['destination_ids' => 'Every destination must belong to the selected client.']);
            }

            return $ids;
        }
        $destination = NotificationDestination::where('client_id', $client->id)->where('enabled', true)->orderByRaw("channel = 'google_chat' desc")->first();
        if (! $destination && filled($client->google_chat_webhook)) {
            $destination = NotificationDestination::updateOrCreate(
                ['client_id' => $client->id, 'name' => 'Default Google Chat', 'channel' => 'google_chat'],
                ['configuration' => ['url' => $client->google_chat_webhook], 'enabled' => true, 'created_by' => $request->user()->id]
            );
        }
        if (! $destination) {
            throw ValidationException::withMessages(['destination_ids' => 'Configure a notification destination before creating a Performance Pulse.']);
        }

        return [$destination->id];
    }

    private function ensureEnabledHasDestination(bool $enabled, array $destinationIds): void
    {
        if ($enabled && $destinationIds === []) {
            throw ValidationException::withMessages(['destination_ids' => 'Choose at least one destination before enabling the daily schedule.']);
        }
    }

    private function payload(PerformancePulse $pulse): PerformancePulse
    {
        return $pulse->load(['client:id,name,objective', 'adAccount:id,client_id,name,platform,currency,timezone', 'campaign:id,ad_account_id,name', 'destinations:id,client_id,name,channel,enabled']);
    }

    private function ensureUniqueScope(int $clientId, int $accountId, ?int $campaignId, ?int $ignoreId = null): void
    {
        $scopeKey = $campaignId ? 'campaign:'.$campaignId : 'account:'.$accountId;
        $exists = PerformancePulse::where('client_id', $clientId)->where('scope_key', $scopeKey)
            ->when($ignoreId, fn ($query, $id) => $query->where('id', '!=', $id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['scope' => 'A Performance Pulse already exists for this account or campaign.']);
        }
    }

    private function auditValues(PerformancePulse $pulse): array
    {
        return $pulse->only(['client_id', 'ad_account_id', 'campaign_id', 'periods', 'local_time', 'include_campaign_breakdown', 'enabled']);
    }
}
