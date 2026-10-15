<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertTemplate extends Model
{
    protected $fillable = ['key', 'name', 'description', 'metric', 'operator', 'threshold', 'severity', 'config', 'active'];
    protected function casts(): array { return ['threshold' => 'decimal:6', 'config' => 'array', 'active' => 'boolean']; }
}
