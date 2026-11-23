<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreativeMediaSetting extends Model
{
    protected $fillable = ['provider', 'enabled', 'api_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'api_key' => 'encrypted'];
    }
}
