<?php

namespace App\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

class AppRegistration extends Model
{
    protected $table = 'shop_apps';

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'previous_secret' => 'encrypted', 'active' => 'boolean', 'data_approved' => 'boolean'];
    }

    public function installs()
    {
        return $this->hasMany(Installation::class, 'app_id');
    }
}
