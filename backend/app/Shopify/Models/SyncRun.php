<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $table = 'shop_sync_runs';

    protected $guarded = ['id'];

    protected $hidden = ['checkpoint'];

    protected function casts(): array
    {
        return ['checkpoint' => 'encrypted:array', 'from_at' => 'immutable_datetime', 'until_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function installation()
    {
        return $this->belongsTo(Installation::class, 'install_id');
    }
}
