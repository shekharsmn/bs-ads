<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ClientAccess
{
    public static function clientsFor(User $user): Builder
    {
        return Client::query()->when(! $user->isAdmin(), fn (Builder $q) => $q->whereHas('users', fn (Builder $uq) => $uq->whereKey($user->id)));
    }

    public static function authorize(User $user, Client|int $client, bool $manage = false): Client
    {
        $model = $client instanceof Client ? $client : Client::findOrFail($client);
        abort_unless($user->canAccessClient($model->id), 403);
        abort_if($manage && ! $user->canManage(), 403, 'Read-only users cannot modify this client.');
        return $model;
    }
}
