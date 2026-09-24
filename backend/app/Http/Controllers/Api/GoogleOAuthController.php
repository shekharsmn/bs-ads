<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleAdsClientFactory;
use Google\Ads\GoogleAds\V23\Services\ListAccessibleCustomersRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request)
    {
        if (! GoogleAdsClientFactory::isConfigured()) {
            return response()->json(['error' => 'Google Ads OAuth is not configured (missing developer token or OAuth client).'], 503);
        }

        $state = Str::random(48);
        Cache::put('google_oauth_state:'.$state, $request->user()->id, now()->addMinutes(15));

        $params = http_build_query([
            'client_id' => config('google_ads.client_id'),
            'redirect_uri' => config('google_ads.oauth_redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;

        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode((string) $request->query('error'))
            );
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if ($state === '' || $code === '') {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('Missing OAuth code or state.')
            );
        }

        $userId = Cache::pull('google_oauth_state:'.$state);
        if (! $userId) {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('Invalid or expired OAuth state. Try connecting again.')
            );
        }

        if (! GoogleAdsClientFactory::isConfigured()) {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('Server Google Ads configuration is incomplete.')
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
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('Token exchange failed: '.$tokenResponse->body())
            );
        }

        $body = $tokenResponse->json();
        $refreshToken = $body['refresh_token'] ?? null;
        if (! $refreshToken) {
            return redirect()->away(
                config('google_ads.frontend_oauth_complete_url').'?error='.urlencode('No refresh token returned. Revoke app access in Google Account and try again with prompt=consent.')
            );
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
}
