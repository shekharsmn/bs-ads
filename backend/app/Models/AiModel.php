<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'label',
        'capabilities',
        'input_price',
        'output_price',
        'enabled',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'input_price' => 'decimal:6',
            'output_price' => 'decimal:6',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function catalogKey(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
