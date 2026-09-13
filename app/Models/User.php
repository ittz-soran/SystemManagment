<?php

namespace App\Models;

use App\Casts\Unreadable;
use App\Support\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role', 'cost_visibility', 'cost_markup_percent', 'is_active', 'language', 'date_language', 'clock_24_hour', 'theme', 'items_per_page', 'display_currency'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
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

    /**
     * The currency this person reads figures in, or null for the shop's own.
     *
     * ⚠️ Null is the answer in three different cases, and all three must read
     * as "no lens": nobody chose one, they chose the base, or they chose a
     * currency the shop has since switched off. The last one matters — a rate
     * nobody maintains any more is a rate that quietly goes wrong, and falling
     * back to the base is the only safe answer.
     *
     * Nothing reads this by itself. A screen has to ask for it and pass it to
     * `money()`, which is deliberate: Section 2b keeps the lens off the till,
     * and a global that every figure consulted would put it back there by
     * accident. See CurrencyLensTest.
     */
    public function lens(): ?Currency
    {
        $code = (string) $this->display_currency;

        if ($code === '' || $code === Money::base()->code) {
            return null;
        }

        $currency = Currency::cached()[$code] ?? null;

        return ($currency !== null && $currency->is_active) ? $currency : null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'items_per_page' => 'integer',
            'clock_24_hour' => 'boolean',
            'cost_markup_percent' => 'integer',

            // Encrypted at rest. A database dump that hands over both the
            // password hashes and the thing that resets them has handed over
            // the shop.
            // App\Casts\Unreadable rather than Laravel's `encrypted`: that one
            // throws when APP_KEY has changed since the value was written, and
            // Eloquent decrypts every cast attribute while working out what is
            // dirty — so saving a user for ANY reason decrypted their
            // authenticator secret. A changed key took out logging out,
            // changing language, changing theme and saving a preference, all at
            // once, on a shop where nothing else was wrong.
            'two_factor_secret' => Unreadable::class,
            'two_factor_recovery_codes' => Unreadable::class.':array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Whether this person has a way back in without the post office.
     *
     * Confirmed, not merely generated: a secret nobody has typed a code back
     * from is worse than none at all, because it looks like a way in on a
     * screen and is not one.
     */
    public function hasAuthenticator(): bool
    {
        // Asked through the attribute bag rather than as a property, because a
        // model loaded with a partial select does not carry these columns and
        // reading them would throw. "Not loaded" has to answer "no" here: a
        // page that cannot tell must not fall over, and offering somebody a
        // setup screen they do not need is the harmless direction to be wrong.
        $attributes = $this->getAttributes();

        if (empty($attributes['two_factor_secret']) || empty($attributes['two_factor_confirmed_at'])) {
            return false;
        }

        // And it has to be readable. A secret encrypted with a key that is gone
        // would otherwise make this say yes, and the sign-in screen would then
        // demand a code that can never be right — locking somebody out of their
        // own shop over a secret nobody can use.
        return $this->two_factor_secret !== null;
    }

    /**
     * Eight one-time codes, for the phone that is lost, wiped, or in a pocket
     * in another city.
     *
     * Without these an authenticator is a second way to be locked out rather
     * than a way back in — which is the whole failure this exists to prevent.
     *
     * @return list<string>
     */
    public static function newRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Spend one, if it is real.
     *
     * Single use, and removed inside the same call that accepts it, so a code
     * read over somebody's shoulder is worth nothing by the time they type it.
     */
    public function spendRecoveryCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $this->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$index]);

                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
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
