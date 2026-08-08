<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_SALES = 'sales';

    public const ROLE_CONTENT_MANAGER = 'content_manager';

    protected $guarded = [];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
}
