<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MetaAccount extends Model
{
    protected $fillable = [
        'user_id', 'label', 'account_id', 'app_id',
        'app_secret', 'access_token', 'account_name', 'currency', 'timezone',
    ];

    protected $hidden = ['app_secret', 'access_token'];

    public function setAppSecretAttribute(string $value): void
    {
        $this->attributes['app_secret'] = Crypt::encryptString($value);
    }

    public function getAppSecretAttribute(string $value): string
    {
        return Crypt::decryptString($value);
    }

    public function setAccessTokenAttribute(string $value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute(string $value): string
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
