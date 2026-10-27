<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdLibrarySearch extends Model
{
    use SoftDeletes;

    protected $fillable = ['created_by', 'related_client_id', 'company_name', 'company_type', 'website_url', 'name', 'search_mode', 'query', 'advertiser_page_id', 'region', 'active_status', 'ad_type', 'exact_phrase', 'details', 'max_results'];

    protected function casts(): array
    {
        return ['exact_phrase' => 'boolean', 'details' => 'boolean', 'max_results' => 'integer'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relatedClient()
    {
        return $this->belongsTo(Client::class, 'related_client_id');
    }

    public function runs()
    {
        return $this->hasMany(AdLibraryRun::class);
    }

    public function tags()
    {
        return $this->belongsToMany(AdLibraryTag::class, 'ad_library_search_tag');
    }
}
