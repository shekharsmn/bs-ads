<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleAccount;
use App\Services\GoogleAdsClientFactory;
use App\Services\GoogleFetchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GoogleAccountController extends Controller
{
    public function oauthPending(Request $request)
    {
        $data = Cache::get('google_oauth_pending:'.$request->user()->id);
        if (! $data) {
            return response()->json(['pending' => false, 'customer_ids' => []]);
        }

        return response()->json([
            'pending' => true,
            'customer_ids' => $data['customer_ids'] ?? [],
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(
            $request->user()->googleAccounts()->get([
                'id', 'label', 'customer_id', 'login_customer_id', 'account_name', 'currency', 'time_zone', 'created_at',
            ])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|string|regex:/^\d{10}$/',
            'login_customer_id' => 'nullable|string|regex:/^\d{10}$/',
            'label' => 'nullable|string|max:255',
        ]);

        $pending = Cache::pull('google_oauth_pending:'.$request->user()->id);
        if (! $pending || empty($pending['refresh_token'])) {
            return response()->json(['error' => 'No pending Google OAuth session. Connect Google Ads again.'], 422);
        }

        $refreshToken = $pending['refresh_token'];
        $allowed = $pending['customer_ids'] ?? [];
        if ($allowed && ! in_array($data['customer_id'], $allowed, true)) {
            return response()->json(['error' => 'Selected customer is not in the accessible accounts list from Google.'], 422);
        }

        $loginCustomerId = $data['login_customer_id'] ?? null;

        try {
            $client = GoogleAdsClientFactory::fromRefreshToken($refreshToken, $loginCustomerId);
            $fetch = new GoogleFetchService($client, $data['customer_id'], $this->periodLiteral('last_30d'));
            $customerRow = $fetch->fetchCustomerRow();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not access Google Ads customer: '.$e->getMessage()], 422);
        }

        $account = $request->user()->googleAccounts()->create([
            'label' => $data['label'] ?? ($customerRow['descriptive_name'] ?? 'My Google Ads Account'),
            'customer_id' => $data['customer_id'],
            'login_customer_id' => $loginCustomerId,
            'refresh_token' => $refreshToken,
            'account_name' => $customerRow['descriptive_name'] ?? null,
            'currency' => $customerRow['currency_code'] ?? null,
            'time_zone' => $customerRow['time_zone'] ?? null,
        ]);

        return response()->json([
            'account' => $account->only(['id', 'label', 'customer_id', 'account_name', 'currency', 'time_zone']),
        ], 201);
    }

    public function update(Request $request, GoogleAccount $googleAccount)
    {
        if ($googleAccount->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'label' => 'nullable|string|max:255',
            'customer_id' => 'required|string|regex:/^\d{10}$/',
            'login_customer_id' => 'nullable|string|regex:/^\d{10}$/',
            'refresh_token' => 'nullable|string',
        ]);

        $loginCustomerId = $data['login_customer_id'] ?? $googleAccount->login_customer_id;
        $token = ! empty($data['refresh_token']) ? $data['refresh_token'] : $googleAccount->refresh_token;

        if ($data['customer_id'] !== $googleAccount->customer_id
            || $loginCustomerId !== $googleAccount->login_customer_id
            || ! empty($data['refresh_token'])) {
            try {
                $client = GoogleAdsClientFactory::fromRefreshToken($token, $loginCustomerId);
                $fetch = new GoogleFetchService($client, $data['customer_id'], $this->periodLiteral('last_30d'));
                $customerRow = $fetch->fetchCustomerRow();
                $googleAccount->account_name = $customerRow['descriptive_name'] ?? $googleAccount->account_name;
                $googleAccount->currency = $customerRow['currency_code'] ?? $googleAccount->currency;
                $googleAccount->time_zone = $customerRow['time_zone'] ?? $googleAccount->time_zone;
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid credentials or customer: '.$e->getMessage()], 422);
            }
        }

        $googleAccount->label = $data['label'] ?? $googleAccount->label;
        $googleAccount->customer_id = $data['customer_id'];
        $googleAccount->login_customer_id = $loginCustomerId;
        if (! empty($data['refresh_token'])) {
            $googleAccount->refresh_token = $data['refresh_token'];
        }
        $googleAccount->save();

        return response()->json([
            'account' => $googleAccount->only(['id', 'label', 'customer_id', 'account_name', 'currency', 'time_zone']),
        ]);
    }

    public function destroy(Request $request, GoogleAccount $googleAccount)
    {
        if ($googleAccount->user_id !== $request->user()->id) {
            abort(403);
        }
        $googleAccount->delete();

        return response()->json(['message' => 'Account removed']);
    }

    private function periodLiteral(string $period): string
    {
        return match ($period) {
            'last_7d' => 'LAST_7_DAYS',
            'last_90d' => 'LAST_90_DAYS',
            default => 'LAST_30_DAYS',
        };
    }
}
