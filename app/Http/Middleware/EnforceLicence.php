<?php

namespace App\Http\Middleware;

use App\Services\Licence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A copy that has stopped being paid for stops being written to.
 *
 * Built to exactly the shape the storage limit taught, because it is the same
 * danger wearing different clothes: a till that will not record a sale is worse
 * for the shopkeeper than an unpaid invoice, and the person they telephone is
 * the seller. The rules that keep it survivable:
 *
 *   Reading never stops. Every screen, report and invoice already written. A
 *   shop locked out of its own records is a shop that will never pay another
 *   invoice — the whole point is to be inconvenient, not to take hostages.
 *
 *   Deleting never stops, and Settings never stops.
 *
 *   Signing in never stops. Learned the hard way on the storage limit, where
 *   Breeze's unnamed login route meant a full shop could not get back in at
 *   all. A request with nobody behind it is signing in or resetting a password.
 *
 * And nothing arrives without warning: a banner from a fortnight before the
 * date, and grace days after it where everything still works.
 */
class EnforceLicence
{
    /** Writes that must work whatever the licence says. */
    private const ALWAYS_ALLOWED = [
        'settings.*',
        'logout',
        'profile.*',
        'password.*',
        'preferences.*',
        'verification.*',
        'authenticator.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Signing in, resetting a password, confirming an email. None of it is
        // the shop using a system it has not paid for.
        if (! auth()->check()) {
            return $next($request);
        }

        $licence = app(Licence::class);

        if (! $licence->isRequired() || $licence->allowsWriting()) {
            return $next($request);
        }

        if (! $this->isWrite($request) || $this->isAllowed($request)) {
            return $next($request);
        }

        $message = auth()->user()?->isAdmin()
            ? $this->adminReason($licence->state())
            : __('This system is not able to save anything new at the moment. Ask the shop owner — reading and printing still work.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_PAYMENT_REQUIRED);
        }

        return back()->withInput()->with('error', $message);
    }

    /** Say which of the four it is, because each needs a different phone call. */
    private function adminReason(string $state): string
    {
        return match ($state) {
            Licence::EXPIRED => __('The licence for this system has run out, so nothing new can be saved. Contact whoever provides it to renew. Reading, printing and deleting all still work.'),
            Licence::WRONG_HOST => __('This licence was issued for a different web address, so nothing new can be saved. Contact whoever provides this system.'),
            Licence::INVALID => __('The licence on this system cannot be read, so nothing new can be saved. Contact whoever provides it.'),
            default => __('There is no licence on this system, so nothing new can be saved. Contact whoever provides it.'),
        };
    }

    private function isWrite(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
    }

    private function isAllowed(Request $request): bool
    {
        // Deleting is how a shop tidies up while it waits, and it costs the
        // seller nothing.
        if (strtoupper((string) $request->input('_method')) === 'DELETE') {
            return true;
        }

        $name = $request->route()?->getName();

        if ($name === null) {
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
