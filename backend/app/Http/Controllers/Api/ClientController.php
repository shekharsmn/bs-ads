<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientAccess;
use App\Services\GoogleChatNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        return ClientAccess::clientsFor($request->user())->with('adAccounts:id,client_id,platform,name,external_id,currency,timezone,status')->withCount(['adAccounts', 'incidents' => fn ($q) => $q->whereNot('status', 'resolved')])->orderBy('name')->get();
    }

    public function show(Request $request, Client $client)
    {
        ClientAccess::authorize($request->user(), $client);
        return $client->load(['users:id,name,email,role', 'adAccounts.connection:id,name,status', 'reportSchedules.adAccount:id,name,platform'])
            ->loadCount(['incidents' => fn ($query) => $query->whereNot('status', 'resolved'), 'adLibrarySearches', 'adLibraryAudits', 'reportRuns']);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->validated($request);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        return response()->json(Client::create($data), 201);
    }

    public function update(Request $request, Client $client)
    {
        ClientAccess::authorize($request->user(), $client, true);
        $client->update($this->validated($request, $client));
        return $client->fresh();
    }

    public function destroy(Request $request, Client $client)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $client->delete();

        return response()->noContent();
    }

    public function assign(Request $request, Client $client)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['user_ids' => 'required|array', 'user_ids.*' => 'integer|exists:users,id']);
        $client->users()->sync($data['user_ids']);
        return $client->load('users:id,name,email,role');
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        return User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'last_login_at']);
    }

    public function updateUser(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['role' => 'required|in:admin,marketer,viewer']);
        abort_if($request->user()->is($user) && $data['role'] !== 'admin', 422, 'You cannot remove your own admin access.');
        $user->update($data);
        return $user;
    }

    public function testChat(Request $request, Client $client, GoogleChatNotifier $notifier)
    {
        ClientAccess::authorize($request->user(), $client, true);
        $notifier->test($client);
        return ['message' => 'Test message sent.'];
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('clients')->ignore($client)],
            'timezone' => 'sometimes|required|timezone', 'currency' => 'sometimes|required|string|size:3',
            'objective' => 'sometimes|required|in:lead_gen,ecommerce,both', 'goals' => 'nullable|array',
            'chat_visibility' => 'sometimes|required|in:internal,client', 'google_chat_webhook' => 'nullable|url|starts_with:https://chat.googleapis.com/',
            'active' => 'sometimes|boolean',
        ]);
    }
}
