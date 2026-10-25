<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportTemplate extends Model
{
    protected $fillable = ['client_id', 'created_by', 'name', 'sections', 'brand_name', 'brand_color', 'logo_url', 'is_default'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'is_default' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
