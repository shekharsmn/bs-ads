<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class Installation extends Model
{
    protected $table = 'shop_installs';

    protected $guarded = ['id'];

    protected $hidden = ['tokens'];

    protected function casts(): array
    {
        return ['tokens' => 'encrypted:array', 'scopes' => 'array', 'expires_at' => 'immutable_datetime', 'refresh_expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'installed_at' => 'immutable_datetime'];
    }

    public function app()
    {
        return $this->belongsTo(AppRegistration::class, 'app_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
