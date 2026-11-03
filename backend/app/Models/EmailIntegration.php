<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailIntegration extends Model
{
    protected $fillable = [
        'provider',
        'enabled',
        'region',
        'port',
        'smtp_username',
        'smtp_password',
        'from_address',
        'from_name',
        'last_tested_at',
        'last_test_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'smtp_username' => 'encrypted',
            'smtp_password' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
