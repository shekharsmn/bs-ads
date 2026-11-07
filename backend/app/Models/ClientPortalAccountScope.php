<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPortalAccountScope extends Model
{
    public const HIDDEN = 'hidden';
    public const ALL_CAMPAIGNS = 'all_campaigns';
    public const SELECTED_CAMPAIGNS = 'selected_campaigns';

    protected $fillable = ['client_id', 'ad_account_id', 'visibility_mode'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function adAccount()
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function campaigns()
    {
        return $this->belongsToMany(Campaign::class, 'client_portal_scope_campaign')->withTimestamps();
    }

    public function campaignIds(): ?array
    {
        return $this->visibility_mode === self::ALL_CAMPAIGNS
            ? null
            : $this->campaigns->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
