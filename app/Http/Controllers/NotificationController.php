<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PushSubscription;
use App\Services\NotificationFeed;
use App\Support\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The bell.
 *
 * ⚠️ **No permission of its own, on purpose.** Every signed-in person has a
 * bell, and what it may say is decided per entry by `NotificationFeed` — a
 * permission on the screen would be the wrong shape entirely, because the
 * question is never "may this person open notifications" but "may this person
 * hear about THIS".
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationFeed $feed) {}

    /**
     * The whole list, and opening it is what marks it read.
     *
     * The mark is taken BEFORE it moves, so the page can still show which
     * entries were new when it was opened — a list that forgets what was unread
     * the instant you look at it cannot answer "what was that thing".
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $mark = $this->feed->mark($user);

        $logs = $this->feed->visible($user)->paginate($user->items_per_page);

        $this->feed->markSeen($user);

        return view('notifications.index', [
            'logs' => $logs,
            'mark' => $mark,
            'tiers' => Notifications::tiersFor($user),
        ]);
    }

    /**
     * What the bell polls for, every half minute while the tab is looked at.
     *
     * ⚠️ Returns data, never markup. The panel is rebuilt in the browser from
     * these values with `textContent`, because a shop's own description field
     * carries whatever a shopkeeper typed into a product name — and a
     * notification is one of the few places in this system where one person's
     * typing is drawn on another person's screen.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $mark = $this->feed->mark($user);

        return response()->json([
            'count' => $this->feed->unreadCount($user),
            'items' => $this->feed->panel($user)->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'unread' => $log->id > $mark,
                'tier' => $log->tier,
                'icon' => Notifications::iconFor($log->action),
                'text' => (string) $log->description,
                'who' => $log->user?->name ?? __('Somebody'),
                'when' => Notifications::when($log->created_at),
                'url' => Notifications::linkFor($log->module, $log->action, $log->record_id),
            ])->all(),
        ]);
    }

    /**
     * This device would like to be buzzed — Soran, 2026-09-17.
     *
     * ⚠️ Keyed by the endpoint, so the same phone subscribing twice updates one
     * row rather than making two. Browsers re-subscribe freely — after an
     * update, after clearing data — and a table of duplicates would mean a
     * shopkeeper getting the same buzz three times.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                // Trimmed hard: it is only ever shown back to somebody deciding
                // which of their own devices to switch off.
                'device' => Str::limit((string) $request->userAgent(), 190, ''),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** And would like to stop. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        if ($endpoint !== '') {
            PushSubscription::where('endpoint_hash', PushSubscription::hashFor($endpoint))
                // ⚠️ Their own devices only. An endpoint is not a secret worth
                // much, but it is not a licence to unsubscribe somebody else.
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** Opening the panel is reading it. */
    public function seen(Request $request): JsonResponse|RedirectResponse
    {
        $this->feed->markSeen($request->user());

        return $request->expectsJson()
            ? response()->json(['count' => 0])
            : back();
    }
}
