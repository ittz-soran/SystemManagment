<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Two people pressed Delete on the same record.
 *
 * **Found by Soran testing the real system, 2026-09-12.** Two users deleted the
 * same invoice at once: the first saw "Sale deleted", the second got a bare
 * **404 Not Found**.
 *
 * Nothing was wrong with the data. The invoice was deleted once, its stock was
 * put back once, and the locks held — that part is exactly right. What was
 * wrong was the sentence. A shopkeeper who presses Delete and is shown "404 Not
 * Found" does not conclude that a colleague got there first; they conclude the
 * system is broken. And the true answer — *somebody else already deleted this*
 * — is something they actually need to know, because it tells them the record
 * is gone rather than that their click failed.
 *
 * The controller never got the chance to say it. `destroy(Sale $sale)` is
 * resolved by Laravel's route model binding BEFORE the method runs, and that
 * binding does not see soft-deleted rows: it throws, the framework turns it
 * into a 404, and no line of the controller is reached. Which is why this is
 * here, at the edge, rather than in seventeen `destroy` methods — every one of
 * them had the same hole, for the same reason.
 *
 * **It only speaks when it is sure.** A record that was really deleted gets the
 * message; an id that never existed gets the ordinary 404 it deserves, because
 * "somebody deleted this" would then be a guess, and a wrong one. That is the
 * whole reason this looks the row up again with `withTrashed()` instead of
 * assuming.
 */
class AlreadyDeleted
{
    /**
     * A kind answer for a delete that arrived second, or null to leave it a 404.
     */
    public static function answer(Throwable $e, Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('DELETE')) {
            return null;
        }

        // Laravel wraps the binding failure in a NotFoundHttpException, so the
        // one that names the model is usually the previous one.
        $missing = $e instanceof ModelNotFoundException ? $e : $e->getPrevious();

        if (! $missing instanceof ModelNotFoundException) {
            return null;
        }

        $model = $missing->getModel();

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        // Only a model that CAN be soft-deleted can have been. Anything else
        // that is missing is missing for a different reason.
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return null;
        }

        $ids = $missing->getIds();

        if ($ids === []) {
            return null;
        }

        $gone = $model::withTrashed()->whereKey($ids)->first();

        // Not there at all, or there and alive — neither is "somebody deleted
        // it a moment ago", and saying so would be inventing an explanation.
        if ($gone === null || ! $gone->trashed()) {
            return null;
        }

        return redirect()->to(self::theListItWasOn($request))->with(
            'warning',
            __('That was already deleted — somebody else got there first.'),
        );
    }

    /**
     * Where to send them: the list the record was on.
     *
     * Derived from the route's own name rather than a table of them, because
     * every one of these is `<thing>.destroy` and its list is `<thing>.index`.
     * A table would be seventeen lines that can fall out of step with the
     * routes; this cannot. The dashboard catches anything unusual — better a
     * page that exists than a guess at one that might not.
     */
    private static function theListItWasOn(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        if (str_ends_with($name, '.destroy')) {
            $list = substr($name, 0, -strlen('.destroy')).'.index';

            if (Route::has($list)) {
                return route($list);
            }
        }

        return route('dashboard');
    }
}
