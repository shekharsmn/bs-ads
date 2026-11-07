<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $table = 'shop_sales';

    protected $guarded = ['id'];

    protected $hidden = [];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    protected function casts(): array
    {
        return ['happened_at' => 'immutable_datetime', 'amount' => 'decimal:6', 'tax' => 'decimal:6', 'discount' => 'decimal:6', 'net' => 'decimal:6'];
    }
}
