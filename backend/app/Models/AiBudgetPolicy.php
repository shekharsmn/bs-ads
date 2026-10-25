<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudgetPolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['monthly_budget' => 'decimal:4', 'enabled' => 'boolean'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
