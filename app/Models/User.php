<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'language', 'theme', 'items_per_page'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /**
     * Section 4: the default set seeded onto a new `user` account. The admin then
     * adds or removes individual permissions freely.
     *
     * @var list<string>
     */
    public const DEFAULT_PERMISSIONS = [
        'auth.login',
        'sales.create',
        'sales.view',
        'purchases.create',
        'purchases.view',
        'products.view',
        'customers.view',
        'suppliers.view',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'items_per_page' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Section 2: admin has full access, always, and cannot be restricted.
     * The check short-circuits to true and never consults user_permissions.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (! $this->is_active) {
            return false;
        }

        return $this->permissions->contains('key', $key);
    }
}
