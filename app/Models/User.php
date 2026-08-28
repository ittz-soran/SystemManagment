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

#[Fillable(['name', 'email', 'password', 'role', 'cost_visibility', 'cost_markup_percent', 'is_active', 'language', 'theme', 'items_per_page'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /** What this person is shown when the screen says what something cost. */
    public const COST_REAL = 'real';

    public const COST_MARKUP = 'markup';

    public const COST_HIDDEN = 'hidden';

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
            'cost_markup_percent' => 'integer',
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
     * Section 2: admin has full access, always, and cannot be restricted — so
     * an admin always sees what things really cost, whatever is stored here.
     */
    public function costVisibility(): string
    {
        return $this->isAdmin() ? self::COST_REAL : ($this->cost_visibility ?? self::COST_REAL);
    }

    public function seesRealCost(): bool
    {
        return $this->costVisibility() === self::COST_REAL;
    }

    /**
     * A cost figure as this person is allowed to work from it.
     *
     * Null means they are not: the screen shows the mask instead. Marked up,
     * the number is wrong in the shop's favour on purpose — a counter working
     * from 1,100 will not sell at 1,050 thinking there is room in it.
     *
     * Everything derived from a cost — a line's value, a stock total, a profit —
     * is worked out from what this returns rather than from the real figure, or
     * the arithmetic on screen would not add up and the real cost would be a
     * subtraction away.
     */
    public function costAsSeen(?int $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        return match ($this->costVisibility()) {
            self::COST_HIDDEN => null,
            self::COST_MARKUP => (int) round($amount * (100 + max(0, (int) $this->cost_markup_percent)) / 100),
            default => $amount,
        };
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
