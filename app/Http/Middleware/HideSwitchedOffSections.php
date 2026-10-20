<?php

namespace App\Http\Middleware;

use App\Support\Sections;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PROJECT_DOC Section 8h: an address that belongs to a section this shop has
 * not got answers 404, as a page that does not exist would.
 *
 * ⚠️ **By address, not by permission.** Buying second-hand opens with
 * `purchases.create` and the currency page with `settings.manage`; both would
 * still answer if the section's permission group were the only thing asked.
 * The admin is turned away exactly like everybody else, because the section is
 * not there to be allowed into.
 */
class HideSwitchedOffSections
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Sections::offFor($request) !== null) {
            abort(404);
        }

        return $next($request);
    }
}
