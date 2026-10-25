<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryTag extends Model
{
    protected $fillable = ['name', 'slug', 'created_by'];

    public function searches()
    {
        return $this->belongsToMany(AdLibrarySearch::class, 'ad_library_search_tag');
    }
}
