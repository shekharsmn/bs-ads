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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

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
            'connection_id' => 'nullable|integer|exists:platform_connections,id',
        ]);

        $connection = isset($data['connection_id'])
            ? PlatformConnection::whereKey($data['connection_id'])->where('platform', 'google')->firstOrFail()
            : null;

        return $this->startOAuth($request->user()->id, [
            'purpose' => 'platform_connection',
            'connection_id' => $connection?->id,
            'name' => ($data['name'] ?? null) ?: $connection?->name ?: 'Google Ads',
            'scope' => $data['scope'] ?? $connection?->scope ?? 'agency',
            'client_id' => ($data['scope'] ?? $connection?->scope) === 'client' ? (int) ($data['client_id'] ?? $connection?->client_id) : null,
        ]);
    }

    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $pendingState = $state !== '' ? Cache::pull('google_oauth_state:'.$state) : null;
        $completionUrl = $this->completionUrl($pendingState);

        if ($request->query('error')) {
            return $this->oauthFailure($completionUrl, 'Google authorization was cancelled or denied.', [
                'provider_error' => mb_substr((string) $request->query('error'), 0, 100),
            ]);
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

        try {
            $tokenResponse = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('google_ads.client_id'),
                'client_secret' => config('google_ads.client_secret'),
                'redirect_uri' => config('google_ads.oauth_redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (Throwable $error) {
            return $this->oauthFailure($completionUrl, 'Google authorization could not be completed.', [
                'exception' => $error::class,
            ]);
        }

        if (! $tokenResponse->successful()) {
            return $this->oauthFailure($completionUrl, 'Google authorization could not be completed.', [
                'status' => $tokenResponse->status(),
            ]);
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

            $attributes = [
                'client_id' => $pendingState['client_id'] ?? null,
                'name' => $pendingState['name'] ?? 'Google Ads',
                'scope' => $pendingState['scope'] ?? 'agency',
                'credentials' => ['refresh_token' => $refreshToken],
                'status' => 'pending',
                'last_error' => null,
            ];
            $connection = isset($pendingState['connection_id'])
                ? PlatformConnection::whereKey($pendingState['connection_id'])->where('platform', 'google')->firstOrFail()
                : new PlatformConnection(['platform' => 'google']);
            $connection->fill($attributes)->save();

            return redirect()->away($completionUrl.'?connected=google&connection_id='.$connection->id);
        }

        try {
            $googleAdsClient = GoogleAdsClientFactory::fromRefreshToken($refreshToken, null);
            $customerService = $googleAdsClient->getCustomerServiceClient();
            $resp = $customerService->listAccessibleCustomers(new ListAccessibleCustomersRequest);
            $resourceNames = iterator_to_array($resp->getResourceNames());
        } catch (\Throwable $e) {
            return $this->oauthFailure(config('google_ads.frontend_oauth_complete_url'), 'Google Ads account access could not be verified.', [
                'exception' => $e::class,
            ]);
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

    private function oauthFailure(string $completionUrl, string $message, array $context = [])
    {
        $reference = strtolower(Str::random(12));
        Log::warning('Google Ads OAuth failed.', ['reference' => $reference, ...$context]);

        return redirect()->away($completionUrl.'?error='.urlencode($message.' Reference: '.$reference));
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
