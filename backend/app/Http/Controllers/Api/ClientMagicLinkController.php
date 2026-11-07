<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ClientLoginToken;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientMagicLinkController extends Controller
{
    private const GENERIC_MESSAGE = 'If that email has client access, a secure sign-in link has been sent.';

    public function request(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower(trim($data['email']));
        $rateKey = 'client-magic-link:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json(['message' => self::GENERIC_MESSAGE], 202);
        }
        RateLimiter::hit($rateKey, 15 * 60);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('role', UserRole::Client->value)->whereNull('disabled_at')->first();
        $invitation = UserInvitation::query()->whereRaw('LOWER(email) = ?', [$email])->where('role', UserRole::Client->value)
            ->whereNull('accepted_at')->whereNull('revoked_at')->first();

        if (! $user && ! $invitation) {
            return response()->json(['message' => self::GENERIC_MESSAGE], 202);
        }

        $rawToken = Str::random(80);
        DB::transaction(function () use ($request, $email, $user, $invitation, $rawToken) {
            ClientLoginToken::query()->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
            ClientLoginToken::create([
                'user_id' => $user?->id,
                'user_invitation_id' => $invitation?->id,
                'email' => $email,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addMinutes(15),
                'requested_ip' => $request->ip(),
                'requested_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        });

        $link = rtrim((string) config('services.google_workspace.frontend_url'), '/').'/client-login?token='.urlencode($rawToken);
        try {
            Mail::raw("Use this secure link to sign in to your SharkAds client portal. It expires in 15 minutes and can be used once:\n\n{$link}\n\nIf you did not request this, you can ignore this email.", fn ($message) => $message->to($email)->subject('Your SharkAds sign-in link'));
            $invitation?->update(['last_sent_at' => now(), 'delivery_error' => null]);
        } catch (\Throwable $error) {
            $invitation?->update(['delivery_error' => Str::limit($error->getMessage(), 2000, '')]);
            Log::warning('Client magic-link delivery failed.', ['email_hash' => hash('sha256', $email), 'exception' => $error::class]);
        }

        return response()->json(['message' => self::GENERIC_MESSAGE], 202);
    }

    public function consume(Request $request, AuditTrail $audit)
    {
        $data = $request->validate(['token' => 'required|string|min:40|max:255']);
        $user = DB::transaction(function () use ($data) {
            $token = ClientLoginToken::query()->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $token || $token->used_at || $token->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'This sign-in link is invalid or has expired.']);
            }

            $user = $token->user;
            if (! $user && $token->invitation) {
                $invitation = $token->invitation()->with('clients:id')->lockForUpdate()->first();
                if ($invitation->accepted_at || $invitation->revoked_at || $invitation->role !== UserRole::Client) {
                    throw ValidationException::withMessages(['token' => 'This client invitation is no longer active.']);
                }
                $existing = User::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->first();
                if ($existing && $existing->role !== UserRole::Client) {
                    throw ValidationException::withMessages(['token' => 'This email belongs to a workspace account.']);
                }
                $user = $existing ?: User::create([
                    'name' => $invitation->name ?: Str::headline(Str::before($invitation->email, '@')),
                    'email' => strtolower($invitation->email),
                    'role' => UserRole::Client->value,
                    'email_verified_at' => now(),
                ]);
                $user->clients()->sync($invitation->clients->pluck('id')->all());
                $invitation->update(['accepted_at' => now(), 'delivery_error' => null]);
            }

            if (! $user || $user->role !== UserRole::Client || $user->disabled_at) {
                throw ValidationException::withMessages(['token' => 'This client account is not available.']);
            }

            $token->update(['used_at' => now(), 'user_id' => $user->id]);
            ClientLoginToken::query()->where('email', $token->email)->whereNull('used_at')->update(['used_at' => now()]);
            $user->update(['last_login_at' => now(), 'email_verified_at' => $user->email_verified_at ?: now()]);

            return $user->fresh('clients:id,name,slug');
        });

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $audit->record('access.client_magic_link_accepted', $user, [], ['client_ids' => $user->clients->pluck('id')->all()], null, $user, $request);

        return ['user' => $user, 'redirect' => '/portal'];
    }
}
