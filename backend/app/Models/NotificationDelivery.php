<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'delivered_at' => 'datetime'];

    public function destination()
    {
        return $this->belongsTo(NotificationDestination::class, 'notification_destination_id');
    }
}
