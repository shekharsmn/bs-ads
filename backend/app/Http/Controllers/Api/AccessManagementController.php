<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public function update(Request $request, User $user, AuditTrail $audit)
    {
        $data = $request->validate([
            'role' => 'sometimes|required|in:admin,marketer,viewer',
            'disabled' => 'sometimes|boolean',
            'client_ids' => 'sometimes|array', 'client_ids.*' => 'integer|exists:clients,id',
        ]);
        $actor = $request->user();
        $before = ['role' => $user->role?->value ?? $user->role, 'disabled' => (bool) $user->disabled_at, 'client_ids' => $user->clients()->pluck('clients.id')->all()];
        $nextRole = $data['role'] ?? $before['role'];
        $nextDisabled = $data['disabled'] ?? $before['disabled'];
        abort_if($actor->is($user) && ($nextRole !== 'admin' || $nextDisabled), 422, 'You cannot remove your own admin access.');
        if (($before['role'] === 'admin' && $nextRole !== 'admin') || (! $before['disabled'] && $nextDisabled && $before['role'] === 'admin')) {
            abort_if(User::where('role', 'admin')->whereNull('disabled_at')->whereKeyNot($user->id)->doesntExist(), 422, 'At least one active administrator is required.');
        }
        DB::transaction(function () use ($user, $data, $actor) {
            if (array_key_exists('role', $data)) {
                $user->role = $data['role'];
            }
            if (array_key_exists('disabled', $data)) {
                $user->disabled_at = $data['disabled'] ? now() : null;
                $user->disabled_by = $data['disabled'] ? $actor->id : null;
            }
            $user->save();
            if (array_key_exists('client_ids', $data)) {
                $user->clients()->sync($data['client_ids']);
            }
            if (($data['disabled'] ?? false) && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
        $after = ['role' => $user->fresh()->role->value, 'disabled' => (bool) $user->fresh()->disabled_at, 'client_ids' => $user->clients()->pluck('clients.id')->all()];
        $audit->record('access.user_updated', $user, $before, $after, null, $actor, $request);

        return $user->fresh()->load('clients:id,name');
    }

    public function history(User $user)
    {
        return AuditEvent::with('actor:id,name,email')->where('entity_type', User::class)->where('entity_id', (string) $user->id)->latest('id')->paginate(30);
    }
}
