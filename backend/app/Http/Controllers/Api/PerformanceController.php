<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CollectAccountMetricsJob;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Services\ClientAccess;
use App\Services\PerformanceReadService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PerformanceController extends Controller
{
    private const REFRESH_COOLDOWN_KEY = 'performance:command-center-refresh-cooldown';

    private const REFRESH_COOLDOWN_SECONDS = 300;

    public function __construct(private readonly PerformanceReadService $performance) {}

    public function accounts(Request $request)
    {
        return [
            'accounts' => $this->performance->accounts($request->user()),
            'refresh' => $this->refreshCooldown(),
        ];
    }

    public function refresh(Request $request)
    {
        $requestedAt = CarbonImmutable::now();
        $availableAt = $requestedAt->addSeconds(self::REFRESH_COOLDOWN_SECONDS);
        if (! Cache::add(self::REFRESH_COOLDOWN_KEY, $availableAt->toIso8601String(), $availableAt)) {
            $refresh = $this->refreshCooldown();

            return response()->json([
                'message' => 'Command Center refresh is cooling down.',
                'refresh' => $refresh,
            ], 429)->header('Retry-After', (string) max(1, $refresh['remaining_seconds']));
        }

        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');
        $accountIds = AdAccount::query()->whereIn('client_id', $clientIds)
            ->where('status', 'active')
            ->whereHas('client', fn ($query) => $query->where('active', true))
            ->orderBy('id')->pluck('id');
        $refreshToken = (string) Str::uuid();

        $accountIds->each(fn (int $accountId) => CollectAccountMetricsJob::dispatch($accountId, null, null, $refreshToken));

        return response()->json([
            'queued_accounts' => $accountIds->count(),
            'requested_at' => $requestedAt->toIso8601String(),
            'refresh' => $this->refreshCooldown(),
        ], 202);
    }

    public function overview(Request $request, AdAccount $account)
    {
        $this->authorizeAccount($request, $account);
        [$start, $end] = $this->dates($request);

        return $this->performance->overview($account->load('client:id,name'), $start, $end);
    }

    public function campaigns(Request $request, AdAccount $account)
    {
        $this->authorizeAccount($request, $account);
        [$start, $end] = $this->dates($request);

        return $this->performance->campaigns($account, $start, $end);
    }

    public function adGroups(Request $request, AdAccount $account, Campaign $campaign)
    {
        $this->authorizeAccount($request, $account);
        abort_unless((int) $campaign->ad_account_id === (int) $account->id, 404);
        [$start, $end] = $this->dates($request);

        return $this->performance->adGroups($account, $campaign, $start, $end);
    }

    public function ads(Request $request, AdAccount $account, Campaign $campaign, AdGroup $adGroup)
    {
        $this->authorizeAccount($request, $account);
        abort_unless((int) $campaign->ad_account_id === (int) $account->id && (int) $adGroup->campaign_id === (int) $campaign->id, 404);
        [$start, $end] = $this->dates($request);

        return $this->performance->ads($account, $campaign, $adGroup, $start, $end);
    }

    private function authorizeAccount(Request $request, AdAccount $account): void
    {
        ClientAccess::authorize($request->user(), $account->client_id);
    }

    private function dates(Request $request): array
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        return [$data['start_date'], $data['end_date']];
    }

    private function refreshCooldown(): array
    {
        $value = Cache::get(self::REFRESH_COOLDOWN_KEY);
        if (! $value) {
            return ['available' => true, 'available_at' => null, 'remaining_seconds' => 0];
        }

        $availableAt = CarbonImmutable::parse($value);
        $remainingSeconds = max(0, $availableAt->getTimestamp() - CarbonImmutable::now()->getTimestamp());
        if ($remainingSeconds === 0) {
            Cache::forget(self::REFRESH_COOLDOWN_KEY);

            return ['available' => true, 'available_at' => null, 'remaining_seconds' => 0];
        }

        return [
            'available' => false,
            'available_at' => $availableAt->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
        ];
    }
}
