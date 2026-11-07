<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $table = 'shop_stores';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['is_development' => 'boolean', 'history_complete' => 'boolean', 'limited_history' => 'boolean', 'history_from' => 'immutable_datetime', 'synced_until' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }

    public function installation()
    {
        return $this->belongsTo(Installation::class, 'active_install_id');
    }
}
