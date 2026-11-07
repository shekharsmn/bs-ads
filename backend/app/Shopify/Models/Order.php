<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'shop_orders';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['placed_at' => 'immutable_datetime', 'source_updated_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'test' => 'boolean', 'deleted' => 'boolean'];
    }
}
