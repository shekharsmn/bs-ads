<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSubscription extends Model
{
    protected $guarded = ['id'];

    public function destination()
    {
        return $this->belongsTo(NotificationDestination::class, 'notification_destination_id');
    }
}
