<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * What is worth telling somebody, and who is allowed to hear it.
 *
 * Asked for by Soran, 2026-09-15: *"notification system to user get last changes
 * or live changes like added new product to products or another one login in
 * your account"*.
 *
 * Everything here is a reading of `activity_logs`, which the shop has recorded
 * since the first day. Nothing writes a second copy of an event — see the
 * migration for why.
 */
final class Notifications
{
    /**
     * ⚠️ Rare, and worth interrupting somebody for.
     *
     * A sign-in from an address this account has not used. Any document
     * deleted. The books archived or reset. A backup that failed. The integrity
     * check finding something. Each of these is either money or trust.
     */
    public const ALERT = 'alert';

    /** Worth a count on the bell, not worth a noise. */
    public const NEWS = 'news';

    /**
     * ⚠️ History only, and this is the important one.
     *
     * Every sale, purchase and payment lands here and rings nothing. A busy
     * shop writes a hundred sales a day, and a hundred bells a day is a bell
     * nobody reads — which is worse than no bell, because the alert that
     * mattered is now buried in it.
     */
    public const ROUTINE = 'routine';

    /** @var list<string> */
    public const TIERS = [self::ALERT, self::NEWS, self::ROUTINE];

    /** What a new person hears until they say otherwise. */
    public const DEFAULT_TIERS = self::ALERT.','.self::NEWS;

    /**
     * Modules where a deletion is somebody removing money from the books.
     *
     * A product deleted is news; an invoice deleted is an alert. The difference
     * is that one of them changes what the shop is owed.
     */
    private const DOCUMENTS = [
        'sales', 'purchases', 'sale_returns', 'purchase_returns',
        'payments', 'expenses', 'stock_adjustments',
    ];

    /**
     * Modules whose entries belong to the person they are about, nobody else.
     *
     * A sign-in is the account owner's business. Showing "Hawkar signed in" to
     * every other user would be turning the bell into a staff tracker, which is
     * not what was asked for and not a thing to build by accident.
     */
    public const PERSONAL = ['auth'];

    /**
     * How far back an address has to have been used to count as familiar.
     *
     * A month is long enough that the shop's own counter, the owner's phone and
     * the accountant who comes on the last Thursday are all recognised, and
     * short enough that an address somebody used once a year ago is treated as
     * new — which is the right answer, because it is.
     */
    public const FAMILIAR_DAYS = 30;

    /**
     * Where a module's entries can be read, when it is not `{module}.view`.
     *
     * ⚠️ Anything not named here and with no matching `.view` permission is
     * admin-only. Failing closed: a module added later gets kept quiet until
     * somebody decides who may hear about it, rather than announced to
     * everybody because nobody thought about it.
     */
    private const PERMISSIONS = [
        'settings' => 'settings.manage',
        'currencies' => 'settings.manage',
        'expense_categories' => 'expense_categories.manage',
        'data' => 'data.manage',
        'backups' => 'settings.manage',
        'licence' => 'settings.manage',
    ];

    /**
     * Which tier an entry belongs to, decided as it is written.
     *
     * Deliberately a plain match on what the log already carries rather than a
     * per-service decision: a rule kept in one place is a rule somebody can
     * read and argue with, and a service that had to remember to classify its
     * own events would eventually forget.
     */
    public static function tierFor(string $action, string $module): string
    {
        return match (true) {
            /*
             * ⚠️ Signing in and out is HISTORY, not an alert — the alert is
             * `signInTier()` raising this one case, and only when the address
             * is one this account has not used.
             *
             * The obvious reading of "tell me when somebody logs into my
             * account" is to ring on every sign-in. Do not: a shopkeeper signs
             * in twice a day from the same counter, and a bell that rings for
             * that is a bell whose red dot means nothing by Thursday — so the
             * one sign-in that was not him is the one nobody looks at.
             */
            $module === 'auth' => self::ROUTINE,

            // Money leaving the books, the shop being reset, a backup that did
            // not happen.
            $action === 'delete' && in_array($module, self::DOCUMENTS, true) => self::ALERT,
            in_array($module, ['backups', 'licence'], true) => self::ALERT,
            $module === 'data' && in_array($action, ['reset', 'archive', 'restore'], true) => self::ALERT,

            // The documents themselves: a hundred a day, and the dashboard
            // already says what the day came to.
            in_array($module, self::DOCUMENTS, true) => self::ROUTINE,

            // Everything else — a product added, a price changed, a supplier
            // added, somebody's permissions changed.
            default => self::NEWS,
        };
    }

    /**
     * The modules this person may be told about.
     *
     * ⚠️ **Never notify somebody about something they cannot open.** A
     * salesperson whose cost visibility is off must not be told "purchase price
     * changed to 38,500" — that hands over the figure the setting exists to
     * hide, through a door nobody thought to lock. The sidebar already refuses
     * to show a link somebody cannot follow; this is the same rule applied to
     * the bell.
     *
     * @return list<string>
     */
    public static function modulesFor(User $user): array
    {
        $modules = array_values(array_unique([
            ...self::DOCUMENTS,
            ...array_keys(self::PERMISSIONS),
            'products', 'categories', 'customers', 'suppliers', 'users',
            'second_hand', 'services', 'stock',
        ]));

        return array_values(array_filter(
            $modules,
            fn (string $module) => $user->hasPermission(self::permissionFor($module)),
        ));
    }

    /** The permission an entry from this module sits behind. */
    public static function permissionFor(string $module): string
    {
        return self::PERMISSIONS[$module] ?? $module.'.view';
    }

    /** Whether this module's entries are only ever for the person named on them. */
    public static function isPersonal(string $module): bool
    {
        return in_array($module, self::PERSONAL, true);
    }

    /**
     * The tiers this person wants sent to their PHONE.
     *
     * ⚠️ Read from its own column, not the bell's. A number on a badge and a
     * buzz in a pocket at eleven at night are not the same event, and somebody
     * may well want everything on the bell and only the serious things on the
     * phone.
     *
     * Alerts are here for the same reason they are on the bell: somebody who
     * has turned the noise down should still be told their own account was
     * signed into from an address they do not use.
     *
     * @return list<string>
     */
    public static function pushTiersFor(User $user): array
    {
        $stored = (string) ($user->getAttributes()['push_tiers'] ?? self::DEFAULT_TIERS);

        return array_values(array_unique([
            self::ALERT,
            ...array_filter(
                array_map(trim(...), explode(',', $stored)),
                fn (string $tier) => in_array($tier, self::TIERS, true),
            ),
        ]));
    }

    /**
     * The tiers this person wants, always including alerts.
     *
     * ⚠️ Alerts are not optional. Somebody who has turned everything off should
     * still be told that their own account was signed into from an address they
     * do not use, and that the invoices were deleted. A preference is about
     * noise, not about being kept in the dark.
     *
     * @return list<string>
     */
    public static function tiersFor(User $user): array
    {
        // Through the attribute bag with the default behind it, not as a
        // property: strict mode turns a column that was never selected into a
        // 500, and this is read on every page in the shop. A user whose
        // preference has not been loaded hears what a new person hears.
        $stored = (string) ($user->getAttributes()['notify_tiers'] ?? self::DEFAULT_TIERS);

        $wanted = array_map(trim(...), explode(',', $stored));

        return array_values(array_unique([
            self::ALERT,
            ...array_filter($wanted, fn (string $tier) => in_array($tier, self::TIERS, true)),
        ]));
    }

    /**
     * Whether this sign-in is worth waking somebody for.
     *
     * Soran asked to be told when *"another one login in your account"*. The
     * useful form of that is not every sign-in — see `tierFor()` — but a
     * sign-in from an address this account has not used, which is what somebody
     * else signing in with your password looks like from the server's side.
     *
     * ⚠️ **The first sign-in an account ever makes is not an alert.** Nothing
     * is familiar yet, so every address is new, and a fresh shop would greet
     * its owner with a warning about himself on the day it was installed.
     *
     * ⚠️ **An address the server could not read is an alert.** Behind a proxy
     * that strips it there is nothing to compare, and the safe direction to be
     * wrong is to mention it.
     */
    public static function signInTier(User $user, ?string $ip): string
    {
        if ((string) $ip === '') {
            return self::ALERT;
        }

        $seen = ActivityLog::query()
            ->where('user_id', $user->getKey())
            ->where('module', 'auth')
            ->where('action', 'login')
            ->where('created_at', '>=', now()->subDays(self::FAMILIAR_DAYS))
            ->distinct()
            ->pluck('ip_address');

        // Nothing to compare against: this account has not signed in within the
        // window, so there is no "usual address" to be away from.
        if ($seen->isEmpty()) {
            return self::ROUTINE;
        }

        return $seen->contains($ip) ? self::ROUTINE : self::ALERT;
    }

    /**
     * Where an entry leads, or null when it leads nowhere.
     *
     * ⚠️ Null far more often than not, and deliberately. A notification that
     * opens a 404 is worse than one that opens nothing: the reader thinks the
     * record is gone when the truth is that the bell guessed at a URL. So a
     * link is built only when there is a real `show` route for that module and
     * a record that still exists to show — never after a delete, which is the
     * entry most likely to be read and the record most certainly not there.
     */
    public static function linkFor(string $module, string $action, ?int $recordId): ?string
    {
        if ($recordId === null || in_array($action, ['delete', 'purge'], true)) {
            return null;
        }

        // Modules are snake ('sale_returns'); route names are kebab
        // ('sale-returns.show'). One is how a table is named and the other is
        // how a URL is written, and this is the seam between them.
        $name = str_replace('_', '-', $module).'.show';

        return Route::has($name) ? route($name, $recordId) : null;
    }

    /**
     * A Bootstrap Icons class for what happened.
     *
     * ⚠️ The whole class name, `bi-pencil` and not `pencil`, so that
     * `tools/subset-icons.py` finds these when it reads the source. The font
     * this shop ships carries sixty glyphs out of two thousand, and an icon the
     * subset does not know about draws as an empty box on a shopkeeper's screen
     * without breaking anything loudly enough to notice.
     */
    public static function iconFor(string $action): string
    {
        return match ($action) {
            'create' => 'bi-plus-circle',
            'update' => 'bi-pencil',
            'delete', 'purge' => 'bi-trash',
            'login' => 'bi-box-arrow-in-right',
            'logout' => 'bi-box-arrow-right',
            'restore' => 'bi-arrow-counterclockwise',
            default => 'bi-dot',
        };
    }

    /** What a tier is called on screen. */
    public static function label(string $tier): string
    {
        return match ($tier) {
            self::ALERT => __('Alerts'),
            self::NEWS => __('News'),
            default => __('History only'),
        };
    }

    /** One line saying what a tier is for, under its switch. */
    public static function explain(string $tier): string
    {
        return match ($tier) {
            self::ALERT => __('A sign-in from an address you do not use, a deleted invoice, the books being reset. Always on.'),
            self::NEWS => __('A product added, a price changed, a new customer — worth knowing, not worth interrupting for.'),
            default => __('Every sale, purchase and payment. Hundreds a day in a busy shop.'),
        };
    }

    /**
     * How long ago, in words, for a bell somebody glances at.
     *
     * Not Carbon's own `diffForHumans()`: it speaks the locales Carbon ships,
     * and Sorani is not one of them — a Kurdish shop would read "2 hours ago"
     * in the middle of a Kurdish sentence. Through `__()` instead, which is the
     * shop's own four languages and which `translations:check` counts.
     *
     * Past a day it says the date. "37 hours ago" is arithmetic the reader has
     * to do; Tuesday's date is not.
     */
    public static function when(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(now(), absolute: true);

        return match (true) {
            $minutes < 1 => __('Just now'),
            $minutes < 60 => __(':count min ago', ['count' => $minutes]),
            $minutes < 60 * 24 => __(':count h ago', ['count' => intdiv($minutes, 60)]),
            default => $at->format('Y-m-d H:i'),
        };
    }
}
