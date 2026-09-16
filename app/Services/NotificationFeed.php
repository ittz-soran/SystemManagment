<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Notifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The bell, read from the activity log.
 *
 * There is no notifications table (see the migration for why). A person's
 * unread list is "the entries they are allowed to hear, newer than the mark
 * they left", and that is the whole of this class.
 */
class NotificationFeed
{
    /** How many the bell's own panel shows before sending somebody to the page. */
    public const PANEL = 8;

    /**
     * Everything this person is allowed to be told about, newest first.
     *
     * Ignores the reading mark — this is the history behind the bell. The
     * unread list is this, cut at `mark()`.
     */
    public function visible(User $user): Builder
    {
        $modules = Notifications::modulesFor($user);

        return ActivityLog::query()
            ->with('user')
            ->whereIn('tier', Notifications::tiersFor($user))
            ->where(function (Builder $query) use ($user, $modules) {
                /*
                 * ⚠️ Somebody else's doing, in a module this person may open.
                 *
                 * Both halves matter. Without `modules` a salesperson whose
                 * cost visibility is off would be told "purchase price changed
                 * to 38,500" — the exact figure the setting exists to hide,
                 * handed over through a door nobody thought to lock. Without
                 * "somebody else" the bell would announce to a shopkeeper the
                 * sale he just rang up himself, which is not news to him.
                 */
                $query->where(function (Builder $mine) use ($user, $modules) {
                    $mine->whereIn('module', $modules)
                        ->where('user_id', '!=', $user->getKey());
                })
                    /*
                     * ⚠️ And this person's own account, which is the opposite
                     * rule: an `auth` entry belongs to the person it is about
                     * and to nobody else. Showing "Hawkar signed in" to every
                     * other user would turn the bell into a staff tracker —
                     * which is not what was asked for, and not a thing to build
                     * by accident.
                     */
                    ->orWhere(function (Builder $personal) use ($user) {
                        $personal->whereIn('module', Notifications::PERSONAL)
                            ->where('user_id', $user->getKey());
                    });
            })
            ->orderByDesc('id');
    }

    /**
     * Where this person's reading stopped: the id of the last entry they saw.
     *
     * ⚠️ An id, not a timestamp. `activity_logs.created_at` is whole seconds,
     * so a mark of "everything up to now" silently swallowed anything written
     * in the same second the bell was opened. Losing a price change that way is
     * survivable; losing the one that says somebody else signed into your
     * account is not.
     *
     * Null only for a row written before this shop was upgraded — everybody
     * else is given a mark as their account is created, see User::booted(). It
     * reads as "read everything", which is the safe direction: the alternative
     * is greeting somebody with the shop's entire history.
     */
    public function mark(User $user): int
    {
        // Through the attribute bag, not as a property: this app runs Eloquent
        // strictly, so a User built by a partial select would THROW on a column
        // it never loaded — and the bell is drawn on every page in the shop.
        $attributes = $user->getAttributes();

        return (int) ($attributes['notifications_seen_id'] ?? PHP_INT_MAX);
    }

    /** @return Builder<ActivityLog> */
    public function unread(User $user): Builder
    {
        return $this->visible($user)->where('id', '>', $this->mark($user));
    }

    /**
     * How many are waiting.
     *
     * Counted no higher than a hundred: the badge says "99+" past that, and
     * counting four thousand rows to draw two characters is work nobody sees.
     */
    public function unreadCount(User $user): int
    {
        return $this->unread($user)->limit(100)->count();
    }

    /**
     * The newest few, whether read or not.
     *
     * The panel shows recent history rather than only unread, because a bell
     * that empties itself the moment you look at it cannot answer "what was
     * that thing I just saw" — and that is the question people actually ask a
     * notification list.
     *
     * @return Collection<int, ActivityLog>
     */
    public function panel(User $user, int $limit = self::PANEL): Collection
    {
        $mark = $this->mark($user);

        return $this->visible($user)->limit($limit)->get()
            ->each(fn (ActivityLog $log) => $log->setAttribute('is_unread', $log->id > $mark));
    }

    /**
     * Move the reading mark to the newest entry in the log.
     *
     * One number rather than a list of ids, which is the whole reason this
     * needs no second table: "read" is a position, and everything behind it is
     * behind it.
     *
     * The newest entry in the WHOLE log, not the newest this person can see: a
     * mark is a place in the log, and an entry they were never allowed to hear
     * about is not one they will be shown later either.
     */
    public function markSeen(User $user): void
    {
        $user->forceFill(['notifications_seen_id' => ActivityLog::max('id') ?? 0])->save();
    }
}
