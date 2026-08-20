<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for a single permission key, e.g. `->middleware('can:sales.create')`
 * equivalent written as `->middleware('permission:sales.create')`.
 *
 * Section 2: admin short-circuits to true inside User::hasPermission(), so an
 * admin never fails this check.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, __('Your account is not active.'));
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, __('You do not have permission to do that.'));
    }
}
