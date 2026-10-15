<?php

namespace App\Models;

use App\Enums\CanonicalMetric;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;

class ConversionMapping extends Model
{
    protected $fillable = ['client_id', 'platform', 'external_action', 'canonical_metric', 'weight', 'version', 'active'];
    protected function casts(): array { return ['platform' => Platform::class, 'canonical_metric' => CanonicalMetric::class, 'weight' => 'decimal:4', 'active' => 'boolean']; }
}
