<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'role' => UserRole::class,
        'run_performance_audits' => 'boolean',
        'run_content_audits' => 'boolean',
        'use_data_inspector' => 'boolean',
        'invited_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_user_invitation')->withTimestamps();
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function status(): string
    {
        return $this->accepted_at ? 'accepted' : ($this->revoked_at ? 'revoked' : 'pending');
    }
}
