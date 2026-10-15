<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertEvent extends Model
{
    protected $fillable = ['alert_incident_id', 'actor_id', 'type', 'note', 'metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function incident() { return $this->belongsTo(AlertIncident::class, 'alert_incident_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
