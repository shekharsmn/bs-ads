<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WorkspaceAuthController extends Controller
{
    public function redirect()
    {
        abort_unless(config('services.google_workspace.client_id'), 503, 'Google Workspace SSO is not configured.');
        $state = Str::random(48);
        Cache::put('workspace_sso:'.$state, true, now()->addMinutes(10));
        $allowedDomains = $this->allowedDomains();
        $params = http_build_query([
            'client_id' => config('services.google_workspace.client_id'),
            'redirect_uri' => config('services.google_workspace.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'hd' => count($allowedDomains) === 1 ? $allowedDomains[0] : '*',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
    }

    public function callback(Request $request)
    {
        $frontend = rtrim(config('services.google_workspace.frontend_url'), '/');
        if ($request->query('error')) {
            return $this->oauthFailure($frontend, 'Google sign-in was cancelled or denied.', [
                'provider_error' => mb_substr((string) $request->query('error'), 0, 100),
            ]);
        }
        abort_unless(Cache::pull('workspace_sso:'.(string) $request->query('state')), 419, 'SSO state expired.');
        try {
            $token = Http::asForm()->connectTimeout(5)->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'code' => $request->query('code'), 'client_id' => config('services.google_workspace.client_id'),
                'client_secret' => config('services.google_workspace.client_secret'), 'redirect_uri' => config('services.google_workspace.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);
            $token->throw();
            $profile = Http::withToken($token->json('access_token'))->connectTimeout(5)->timeout(20)->retry(2, 250, null, false)->get('https://openidconnect.googleapis.com/v1/userinfo');
            $profile->throw();
        } catch (Throwable $error) {
            return $this->oauthFailure($frontend, 'Google sign-in could not be completed.', [
                'exception' => $error::class,
            ]);
        }
        $email = strtolower((string) $profile->json('email'));
        $allowedDomains = $this->allowedDomains();
        $hostedDomain = strtolower((string) $profile->json('hd'));
        abort_unless(
            $profile->json('email_verified') && in_array($hostedDomain, $allowedDomains, true),
            403,
            'Only verified accounts from an allowed Google Workspace domain may sign in.'
        );
        $bootstrapEmail = strtolower(trim((string) config('services.google_workspace.bootstrap_admin_email', '')));
        $shouldBootstrap = $bootstrapEmail !== ''
            && hash_equals($bootstrapEmail, $email)
            && User::query()->where('role', 'admin')->whereNull('disabled_at')->doesntExist();
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $profile->json('name') ?: Str::before($email, '@'),
            'google_subject' => $profile->json('sub'), 'avatar_url' => $profile->json('picture'),
            'email_verified_at' => now(), 'last_login_at' => now(),
            ...($shouldBootstrap ? ['role' => 'admin'] : []),
        ]);
        abort_if($user->disabled_at, 403, 'This account has been disabled.');
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->away($frontend);
    }

    private function oauthFailure(string $frontend, string $message, array $context = [])
    {
        $reference = strtolower(Str::random(12));
        Log::warning('Google Workspace OAuth failed.', ['reference' => $reference, ...$context]);

        return redirect()->away($frontend.'/login?error='.urlencode($message.' Reference: '.$reference));
    }

    /** @return list<string> */
    private function allowedDomains(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            (array) config('services.google_workspace.allowed_domains', [])
        ))));
    }
}
