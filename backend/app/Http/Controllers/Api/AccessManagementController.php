<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccessManagementController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,disabled', 'per_page' => 'nullable|integer|min:10|max:100']);
        return User::query()->with('clients:id,name')->withCount('clients')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when(($validated['status'] ?? null) === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->when(($validated['status'] ?? null) === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->orderBy('name')->paginate($validated['per_page'] ?? 25);
    }

    public function directory(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:100', 'status' => 'nullable|in:active,disabled,pending,revoked',
            'role' => 'nullable|in:admin,marketer,viewer', 'permission' => 'nullable|in:account,ad_library,data_inspector,performance,content',
        ]);
        $search = $data['search'] ?? null; $status = $data['status'] ?? null;
        $role = $data['role'] ?? null; $permission = $data['permission'] ?? null;
        $users = User::query()->with('clients:id,name')->withCount('clients')
            ->when($search, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($status === 'active', fn ($q) => $q->whereNull('disabled_at'))
            ->when($status === 'disabled', fn ($q) => $q->whereNotNull('disabled_at'))
            ->when(in_array($status, ['pending', 'revoked'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($role, fn ($q, $value) => $q->where('role', $value))
            ->when(in_array($permission, ['account', 'performance'], true), fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('run_performance_audits', true)))
            ->when(in_array($permission, ['ad_library', 'content'], true), fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('run_content_audits', true)))
            ->when($permission === 'data_inspector', fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('use_data_inspector', true)))
            ->orderBy('name')->get()->map(fn (User $user) => $this->serializeUser($user));
        $invitations = UserInvitation::query()->with(['clients:id,name', 'inviter:id,name'])->whereNull('accepted_at')
            ->when($search, fn ($q, $term) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when(in_array($status, ['active', 'disabled'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($status === 'pending', fn ($q) => $q->whereNull('revoked_at'))
            ->when($status === 'revoked', fn ($q) => $q->whereNotNull('revoked_at'))
            ->when($role, fn ($q, $value) => $q->where('role', $value))
            ->when(in_array($permission, ['account', 'performance'], true), fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('run_performance_audits', true)))
            ->when(in_array($permission, ['ad_library', 'content'], true), fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('run_content_audits', true)))
            ->when($permission === 'data_inspector', fn ($q) => $q->where(fn ($nested) => $nested->where('role', 'admin')->orWhere('use_data_inspector', true)))
            ->latest('id')->get()->map(fn (UserInvitation $invitation) => $this->serializeInvitation($invitation));
        return ['data' => $users->concat($invitations)->values(), 'summary' => [
            'active' => User::whereNull('disabled_at')->count(),
            'pending' => UserInvitation::whereNull('accepted_at')->whereNull('revoked_at')->count(),
            'disabled' => User::whereNotNull('disabled_at')->count(),
            'admins' => User::where('role', 'admin')->whereNull('disabled_at')->count(),
        ]];
    }

    public function invite(Request $request, AuditTrail $audit)
    {
        $data = $this->validatedAccess($request, true); $email = strtolower(trim($data['email']));
        $this->validateWorkspaceEmail($email);
        abort_if(User::whereRaw('LOWER(email) = ?', [$email])->exists(), 422, 'This person already has a user account. Update or reactivate that account instead.');
        $existing = UserInvitation::whereRaw('LOWER(email) = ?', [$email])->first();
        abort_if($existing && ! $existing->revoked_at && ! $existing->accepted_at, 422, 'A pending invitation already exists for this email address.');
        abort_if($existing?->accepted_at, 422, 'This invitation has already been accepted.');
        $invitation = DB::transaction(function () use ($data, $email, $request, $existing) {
            $invitation = $existing ?: new UserInvitation();
            $role = $data['role'];
            $invitation->fill([
                'email' => $email, 'name' => trim((string) ($data['name'] ?? '')) ?: Str::headline(Str::before($email, '@')),
                'role' => $role, 'run_performance_audits' => $role === 'admin' || ($data['run_performance_audits'] ?? false),
                'run_content_audits' => $role === 'admin' || ($data['run_content_audits'] ?? false),
                'use_data_inspector' => $role === 'admin' || ($data['use_data_inspector'] ?? false),
                'invited_by' => $request->user()->id, 'invited_at' => now(), 'last_sent_at' => now(), 'accepted_at' => null, 'revoked_at' => null,
            ])->save();
            $invitation->clients()->sync($data['client_ids'] ?? []);
            return $invitation->fresh(['clients:id,name', 'inviter:id,name']);
        });
        $this->sendInvitation($invitation);
        $audit->record('access.invitation_created', $invitation, [], $this->invitationAuditValues($invitation), null, $request->user(), $request);
        return response()->json($this->serializeInvitation($invitation), 201);
    }

    public function updateInvitation(Request $request, UserInvitation $invitation, AuditTrail $audit)
    {
        abort_if($invitation->accepted_at || $invitation->revoked_at, 422, 'Only pending invitations can be edited.');
        $data = $this->validatedAccess($request); $before = $this->invitationAuditValues($invitation->load('clients:id,name'));
        DB::transaction(function () use ($invitation, $data) {
            $role = $data['role'] ?? $invitation->role->value;
            $invitation->fill(collect($data)->except('client_ids')->all());
            if ($role === 'admin') { $invitation->run_performance_audits = true; $invitation->run_content_audits = true; $invitation->use_data_inspector = true; }
            $invitation->save();
            if (array_key_exists('client_ids', $data)) $invitation->clients()->sync($data['client_ids']);
        });
        $invitation = $invitation->fresh(['clients:id,name', 'inviter:id,name']);
        $audit->record('access.invitation_updated', $invitation, $before, $this->invitationAuditValues($invitation), null, $request->user(), $request);
        return $this->serializeInvitation($invitation);
    }

    public function resend(Request $request, UserInvitation $invitation, AuditTrail $audit)
    {
        abort_if($invitation->accepted_at || $invitation->revoked_at, 422, 'Only pending invitations can be resent.');
        $invitation->update(['last_sent_at' => now()]); $this->sendInvitation($invitation);
        $audit->record('access.invitation_resent', $invitation, [], ['last_sent_at' => $invitation->last_sent_at], null, $request->user(), $request);
        return $this->serializeInvitation($invitation->fresh(['clients:id,name', 'inviter:id,name']));
    }

    public function revoke(Request $request, UserInvitation $invitation, AuditTrail $audit)
    {
        abort_if($invitation->accepted_at || $invitation->revoked_at, 422, 'Only pending invitations can be revoked.');
        $invitation->update(['revoked_at' => now()]);
        $audit->record('access.invitation_revoked', $invitation, ['status' => 'pending'], ['status' => 'revoked'], null, $request->user(), $request);
        return $this->serializeInvitation($invitation->fresh(['clients:id,name', 'inviter:id,name']));
    }

    public function update(Request $request, User $user, AuditTrail $audit)
    {
        $data = $request->validate([
            'role' => 'sometimes|required|in:admin,marketer,viewer', 'disabled' => 'sometimes|boolean',
            'run_performance_audits' => 'sometimes|boolean', 'run_content_audits' => 'sometimes|boolean', 'use_data_inspector' => 'sometimes|boolean',
            'client_ids' => 'sometimes|array', 'client_ids.*' => 'integer|distinct|exists:clients,id',
        ]);
        $actor = $request->user(); $before = $this->userAuditValues($user->load('clients:id,name'));
        $nextRole = $data['role'] ?? $before['role']; $nextDisabled = $data['disabled'] ?? $before['disabled'];
        abort_if($actor->is($user) && ($nextRole !== 'admin' || $nextDisabled), 422, 'You cannot remove your own admin access.');
        if (($before['role'] === 'admin' && $nextRole !== 'admin') || (! $before['disabled'] && $nextDisabled && $before['role'] === 'admin')) {
            abort_if(User::where('role', 'admin')->whereNull('disabled_at')->whereKeyNot($user->id)->doesntExist(), 422, 'At least one active administrator is required.');
        }
        DB::transaction(function () use ($user, $data, $actor, $nextRole) {
            $user->fill(collect($data)->except(['disabled', 'client_ids'])->all());
            if ($nextRole === 'admin') { $user->run_performance_audits = true; $user->run_content_audits = true; $user->use_data_inspector = true; }
            if (array_key_exists('disabled', $data)) { $user->disabled_at = $data['disabled'] ? now() : null; $user->disabled_by = $data['disabled'] ? $actor->id : null; }
            $user->save();
            if (array_key_exists('client_ids', $data)) $user->clients()->sync($data['client_ids']);
            if (($data['disabled'] ?? false) && Schema::hasTable('sessions')) DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        $user = $user->fresh(['clients:id,name']);
        $after = $this->userAuditValues($user);
        $audit->record($this->userUpdateAction($before, $after), $user, $before, $after, null, $actor, $request);
        return $this->serializeUser($user);
    }

    public function history(User $user)
    {
        return AuditEvent::with('actor:id,name,email')->where('entity_type', User::class)->where('entity_id', (string) $user->id)->latest('id')->paginate(30);
    }

    private function validatedAccess(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'email' => [$creating ? 'required' : 'prohibited', 'email', 'max:255'], 'name' => 'nullable|string|max:255',
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(['admin', 'marketer', 'viewer'])],
            'run_performance_audits' => 'sometimes|boolean', 'run_content_audits' => 'sometimes|boolean', 'use_data_inspector' => 'sometimes|boolean',
            'client_ids' => 'sometimes|array', 'client_ids.*' => 'integer|distinct|exists:clients,id',
        ]);
    }

    private function validateWorkspaceEmail(string $email): void
    {
        $domain = strtolower((string) Str::afterLast($email, '@'));
        $allowed = array_values(array_filter(array_map(fn ($value) => strtolower(trim((string) $value)), (array) config('services.google_workspace.allowed_domains', []))));
        abort_unless(in_array($domain, $allowed, true), 422, 'Invitations are limited to configured Google Workspace domains.');
    }

    private function sendInvitation(UserInvitation $invitation): void
    {
        $loginUrl = rtrim((string) config('services.google_workspace.frontend_url'), '/').'/login?invited=1';
        $role = Str::headline($invitation->role->value);
        Mail::raw("You have been invited to SharkAds as {$role}. Sign in with your Google Workspace account: {$loginUrl}", fn ($message) => $message->to($invitation->email)->subject('You are invited to SharkAds'));
    }

    private function serializeUser(User $user): array
    {
        $role = $user->role->value;
        return ['type' => 'user', 'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role,
            'status' => $user->disabled_at ? 'disabled' : 'active', 'disabled_at' => $user->disabled_at, 'last_login_at' => $user->last_login_at,
            'clients_count' => $user->clients->count(), 'clients' => $user->clients,
            'run_performance_audits' => $role === 'admin' || (bool) $user->run_performance_audits,
            'run_content_audits' => $role === 'admin' || (bool) $user->run_content_audits,
            'use_data_inspector' => $role === 'admin' || (bool) $user->use_data_inspector];
    }

    private function serializeInvitation(UserInvitation $invitation): array
    {
        $role = $invitation->role->value;
        return ['type' => 'invitation', 'id' => $invitation->id, 'name' => $invitation->name, 'email' => $invitation->email, 'role' => $role,
            'status' => $invitation->status(), 'clients_count' => $invitation->clients->count(), 'clients' => $invitation->clients,
            'run_performance_audits' => $role === 'admin' || (bool) $invitation->run_performance_audits,
            'run_content_audits' => $role === 'admin' || (bool) $invitation->run_content_audits,
            'use_data_inspector' => $role === 'admin' || (bool) $invitation->use_data_inspector,
            'invited_at' => $invitation->invited_at, 'last_sent_at' => $invitation->last_sent_at, 'inviter' => $invitation->inviter];
    }

    private function userAuditValues(User $user): array
    {
        return ['role' => $user->role->value, 'disabled' => (bool) $user->disabled_at, 'run_performance_audits' => (bool) $user->run_performance_audits, 'run_content_audits' => (bool) $user->run_content_audits, 'use_data_inspector' => (bool) $user->use_data_inspector, 'client_ids' => $user->clients->pluck('id')->all()];
    }

    private function invitationAuditValues(UserInvitation $invitation): array
    {
        return ['email' => $invitation->email, 'role' => $invitation->role->value, 'run_performance_audits' => (bool) $invitation->run_performance_audits, 'run_content_audits' => (bool) $invitation->run_content_audits, 'use_data_inspector' => (bool) $invitation->use_data_inspector, 'client_ids' => $invitation->clients->pluck('id')->all(), 'status' => $invitation->status()];
    }

    private function userUpdateAction(array $before, array $after): string
    {
        $changed = collect($after)->filter(fn ($value, $key) => ($before[$key] ?? null) !== $value)->keys();
        if ($changed->count() !== 1) return 'access.user_updated';
        return match ($changed->first()) {
            'disabled' => $after['disabled'] ? 'access.user_disabled' : 'access.user_reactivated',
            'role' => 'access.user_role_updated',
            'client_ids' => 'access.user_clients_updated',
            'run_performance_audits', 'run_content_audits', 'use_data_inspector' => 'access.user_permissions_updated',
            default => 'access.user_updated',
        };
    }
}
