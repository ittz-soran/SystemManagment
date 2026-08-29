<?php

namespace App\Http\Middleware;

use App\Services\StorageQuota;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop that has used all the space it pays for stops being able to write.
 *
 * That is the plan being a plan, and it is also the most dangerous thing in
 * this system: a till that will not record a sale is worse for the shopkeeper
 * than a full disk, and it is the seller they will telephone about it. So it is
 * built to be survivable rather than merely correct.
 *
 * Reading never stops. Every screen, every report, every invoice already
 * written stays open — the shop can still look up what a customer owes and
 * still print what it sold yesterday.
 *
 * Deleting never stops. Space is freed by removing things, so a block that
 * also blocked deleting would be a door locked from both sides.
 *
 * Settings never stops, so the admin can shorten backup retention — which is
 * the one lever inside the system that actually frees space — and can read the
 * storage page that explains what is happening.
 *
 * Signing out never stops, because being unable to leave a screen that refuses
 * to work is its own kind of trapped.
 *
 * And nobody arrives at this without warning: the meter is amber from 80% and
 * red from 95%, with a banner on every page an admin opens.
 */
class EnforceStorageQuota
{
    /** Writes that must work even when there is no room, and why. */
    private const ALWAYS_ALLOWED = [
        // The way to free space, and the way to read about it.
        'settings.*',
        // Leaving.
        'logout',
        // The reader's own preferences. They cost nothing, and being unable to
        // change the language while the shop is full is only petty.
        'profile.*',
        'password.*',
        'preferences.*',
        'verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $quota = app(StorageQuota::class);

        /*
         * Nobody signed in is signing in.
         *
         * This was very nearly the worst bug in the system. Logging in is a
         * POST, and Breeze's login route carries no name — so the allowlist
         * below, which reads route names, returned "not allowed" and a shop
         * that filled its plan could not get back in. Not the owner either,
         * which meant nobody could reach the one screen that explains it or
         * free a single byte. The whole shop, locked out of its own records, by
         * a quota meant to stop it writing new ones.
         *
         * The suite never saw it because tests sign in with actingAs() rather
         * than by posting a form. A browser found it in one click.
         *
         * So the rule is stated where it belongs: a request with nobody behind
         * it is signing in, resetting a password or confirming an email. None
         * of those is the shop's data growing.
         */
        if (! auth()->check()) {
            return $next($request);
        }

        if (! $quota->isLimited() || ! $this->isWrite($request) || $this->isAllowed($request)) {
            return $next($request);
        }

        if (! $quota->isFull()) {
            return $next($request);
        }

        $message = auth()->user()?->isAdmin()
            ? __('There is no storage left, so nothing new can be saved. Free space by deleting what is no longer needed, or contact whoever provides this system to raise the limit. Reading, printing and deleting all still work.')
            : __('There is no storage left, so nothing new can be saved. Ask the shop owner — reading and printing still work in the meantime.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_INSUFFICIENT_STORAGE);
        }

        // Back where they were, with what they typed still in the boxes: a shop
        // assistant who has just keyed a twelve-line sale should not also lose
        // the sale.
        return back()->withInput()->with('error', $message);
    }

    /**
     * Creating and editing are writes. Deleting is not, on purpose.
     *
     * A delete makes room, so refusing one would leave a shop with no way out
     * of the state this middleware puts them in.
     */
    private function isWrite(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
    }

    private function isAllowed(Request $request): bool
    {
        // A form that spoofs DELETE arrives as a POST. It is still a delete,
        // and deleting is how a shop gets out of this.
        if (strtoupper((string) $request->input('_method')) === 'DELETE') {
            return true;
        }

        $name = $request->route()?->getName();

        if ($name === null) {
            // An unnamed write is one of Breeze's own auth routes, and those
            // are reached by a guest, who never gets this far. Anything else
            // unnamed is shop data and is refused.
            return false;
        }

        foreach (self::ALWAYS_ALLOWED as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
