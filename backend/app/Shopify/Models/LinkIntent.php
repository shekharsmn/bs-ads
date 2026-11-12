<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class LinkIntent extends Model
{
    protected $table = 'shop_links';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'replace' => 'boolean'];
    }
}
