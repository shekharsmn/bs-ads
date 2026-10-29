<?php

namespace App\Models;

use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_subject',
        'role',
        'run_performance_audits',
        'run_content_audits',
        'use_data_inspector',
        'avatar_url',
        'last_login_at',
        'disabled_at',
        'disabled_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'run_performance_audits' => 'boolean',
            'run_content_audits' => 'boolean',
            'use_data_inspector' => 'boolean',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function metaAccounts()
    {
        return $this->hasMany(MetaAccount::class);
    }

    public function googleAccounts()
    {
        return $this->hasMany(GoogleAccount::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin && ! $this->disabled_at;
    }

    public function canManage(): bool
    {
        return ! $this->disabled_at && ($this->role?->canManage() ?? false);
    }

    public function canRunPerformanceAudits(): bool
    {
        return ! $this->disabled_at && ($this->isAdmin() || $this->run_performance_audits);
    }

    public function canRunContentAudits(): bool
    {
        return ! $this->disabled_at && ($this->isAdmin() || $this->run_content_audits);
    }

    public function canUseDataInspector(): bool
    {
        return ! $this->disabled_at && ($this->isAdmin() || $this->use_data_inspector);
    }

    public function canAccessClient(int $clientId): bool
    {
        return $this->isAdmin() || $this->clients()->whereKey($clientId)->exists();
    }
}
