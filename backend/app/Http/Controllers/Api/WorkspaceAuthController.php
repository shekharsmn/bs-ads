<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WorkspaceAuthController extends Controller
{
    public function redirect()
    {
        abort_unless(config('services.google_workspace.client_id'), 503, 'Google Workspace SSO is not configured.');
        $state = Str::random(48);
        Cache::put('workspace_sso:'.$state, true, now()->addMinutes(10));
        $params = http_build_query([
            'client_id' => config('services.google_workspace.client_id'),
            'redirect_uri' => config('services.google_workspace.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'hd' => config('services.google_workspace.allowed_domain'),
            'prompt' => 'select_account',
            'state' => $state,
        ]);
        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
    }

    public function callback(Request $request)
    {
        $frontend = rtrim(config('services.google_workspace.frontend_url'), '/');
        if ($request->query('error')) return redirect()->away($frontend.'/login?error='.urlencode((string) $request->query('error')));
        abort_unless(Cache::pull('workspace_sso:'.(string) $request->query('state')), 419, 'SSO state expired.');
        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'), 'client_id' => config('services.google_workspace.client_id'),
            'client_secret' => config('services.google_workspace.client_secret'), 'redirect_uri' => config('services.google_workspace.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);
        $token->throw();
        $profile = Http::withToken($token->json('access_token'))->get('https://openidconnect.googleapis.com/v1/userinfo');
        $profile->throw();
        $email = strtolower((string) $profile->json('email'));
        $domain = strtolower((string) config('services.google_workspace.allowed_domain'));
        abort_unless($profile->json('email_verified') && str_ends_with($email, '@'.$domain), 403, "Only verified @$domain accounts may sign in.");
        $isFirst = User::query()->count() === 0;
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $profile->json('name') ?: Str::before($email, '@'),
            'google_subject' => $profile->json('sub'), 'avatar_url' => $profile->json('picture'),
            'email_verified_at' => now(), 'last_login_at' => now(),
            ...($isFirst ? ['role' => 'admin'] : []),
        ]);
        Auth::login($user, true);
        $request->session()->regenerate();
        return redirect()->away($frontend);
    }
}
