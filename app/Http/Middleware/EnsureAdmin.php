<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 9: "Users — CRUD, admin only."
 *
 * Not a permission key, deliberately. Every key in the catalogue is something an
 * admin can hand to a member of staff, and the one thing that must never be
 * handed out is the screen that hands things out: whoever can save a user can
 * save one with role = admin, or set a new password on the owner's account. A
 * permission that grants every other permission is not a permission.
 *
 * So this asks the role directly, and nothing in the permission editor can
 * switch it on.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, __('Your account is not active.'));
        }

        if (! $user->isAdmin()) {
            abort(403, __('You do not have permission to do that.'));
        }

        return $next($request);
    }
}
