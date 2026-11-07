<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    protected $table = 'shop_privacy';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['order_gids' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
