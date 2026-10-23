<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\GoogleAdsClientFactory;
use Google\Ads\GoogleAds\V23\Services\ListAccessibleCustomersRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request)
    {
        return $this->startOAuth($request->user()->id);
    }

    public function connectionRedirect(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'scope' => 'required|in:agency,client',
            'client_id' => [
                Rule::requiredIf($request->query('scope') === 'client'),
                'nullable',
                'integer',
                'exists:clients,id',
            ],
        ]);

        return $this->startOAuth($request->user()->id, [
            'purpose' => 'platform_connection',
            'name' => ($data['name'] ?? null) ?: 'Google Ads',
            'scope' => $data['scope'],
            'client_id' => $data['scope'] === 'client' ? (int) $data['client_id'] : null,
        ]);
    }

    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $pendingState = $state !== '' ? Cache::pull('google_oauth_state:'.$state) : null;
        $completionUrl = $this->completionUrl($pendingState);

        if ($request->query('error')) {
            return redirect()->away(
                $completionUrl.'?error='.urlencode((string) $request->query('error'))
            );
        }

        $code = (string) $request->query('code', '');
        if ($state === '' || $code === '') {
            return redirect()->away(
                $completionUrl.'?error='.urlencode('Missing OAuth code or state.')
            );
        }

        if (! $pendingState) {
            return redirect()->away(
                $completionUrl.'?error='.urlencode('Invalid or expired OAuth state. Try connecting again.')
            );
        }

        $userId = is_array($pendingState) ? $pendingState['user_id'] : $pendingState;

        if (! GoogleAdsClientFactory::isConfigured()) {
            return redirect()->away(
                $completionUrl.'?error='.urlencode('Server Google Ads configuration is incomplete.')
            );
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('google_ads.client_id'),
            'client_secret' => config('google_ads.client_secret'),
            'redirect_uri' => config('google_ads.oauth_redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenResponse->successful()) {
            return redirect()->away(
                $completionUrl.'?error='.urlencode('Token exchange failed: '.$tokenResponse->body())
            );
        }

        $body = $tokenResponse->json();
        $refreshToken = $body['refresh_token'] ?? null;
        if (! $refreshToken) {
            return redirect()->away(
                $completionUrl.'?error='.urlencode('No refresh token returned. Revoke app access in Google Account and try again with prompt=consent.')
            );
        }

        if (is_array($pendingState) && ($pendingState['purpose'] ?? null) === 'platform_connection') {
            $user = User::find($userId);
            if (! $user?->isAdmin()) {
                return redirect()->away($completionUrl.'?error='.urlencode('Your admin access is no longer active.'));
            }

            $connection = PlatformConnection::create([
                'client_id' => $pendingState['client_id'] ?? null,
                'platform' => 'google',
                'name' => $pendingState['name'] ?? 'Google Ads',
                'scope' => $pendingState['scope'] ?? 'agency',
                'credentials' => ['refresh_token' => $refreshToken],
                'status' => 'pending',
            ]);

            return redirect()->away($completionUrl.'?connected=google&connection_id='.$connection->id);
        }

        try {
            $googleAdsClient = GoogleAdsClientFactory::fromRefreshToken($refreshToken, null);
            $customerService = $googleAdsClient->getCustomerServiceClient();
            $resp = $customerService->listAccessibleCustomers(new ListAccessibleCustomersRequest);
            $resourceNames = iterator_to_array($resp->getResourceNames());
        } catch (\Throwable $e) {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('Google Ads API error: '.$e->getMessage())
            );
        }

        $customerIds = array_values(array_filter(array_map(function (string $name) {
            if (preg_match('#customers/(\d+)#', $name, $m)) {
                return $m[1];
            }

            return null;
        }, $resourceNames)));

        Cache::put('google_oauth_pending:'.$userId, [
            'refresh_token' => $refreshToken,
            'customer_ids' => $customerIds,
        ], now()->addMinutes(15));

        return redirect()->away(config('google_ads.frontend_oauth_complete_url'));
    }

    private function startOAuth(int $userId, array $context = [])
    {
        if (! GoogleAdsClientFactory::isConfigured()) {
            return response()->json(['error' => 'Google Ads OAuth is not configured (missing developer token or OAuth client).'], 503);
        }

        $state = Str::random(48);
        Cache::put('google_oauth_state:'.$state, $context ? $context + ['user_id' => $userId] : $userId, now()->addMinutes(15));
        $params = http_build_query([
            'client_id' => config('google_ads.client_id'),
            'redirect_uri' => config('google_ads.oauth_redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
    }

    private function completionUrl(mixed $pendingState): string
    {
        if (is_array($pendingState) && ($pendingState['purpose'] ?? null) === 'platform_connection') {
            return rtrim(config('services.google_workspace.frontend_url'), '/').'/connections';
        }

        return config('google_ads.frontend_oauth_complete_url');
    }
}
