<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientLoginToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invitation()
    {
        return $this->belongsTo(UserInvitation::class, 'user_invitation_id');
    }
}
