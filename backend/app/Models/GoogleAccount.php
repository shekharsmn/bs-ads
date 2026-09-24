<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GoogleAccount extends Model
{
    protected $fillable = [
        'user_id', 'label', 'customer_id', 'login_customer_id',
        'refresh_token', 'account_name', 'currency', 'time_zone',
    ];

    protected $hidden = ['refresh_token'];

    public function setRefreshTokenAttribute(string $value): void
    {
        $this->attributes['refresh_token'] = Crypt::encryptString($value);
    }

    public function getRefreshTokenAttribute(string $value): string
    {
        return Crypt::decryptString($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }
}
