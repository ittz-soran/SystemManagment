<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Section 8b: "A bulk-delete action is available on list pages, but it is a
 * loop of the normal single-delete logic, not a mass DELETE."
 *
 * - Each row runs its own canBeModified() check.
 * - Rows that fail are skipped and reported.
 * - Rows still referenced by a foreign key are refused with the reason.
 * - The whole batch runs in one transaction; any unexpected error rolls all of
 *   it back.
 *
 * That last rule is the awkward one: a *refusal* must not roll the batch back,
 * or one locked row would undo eleven good deletions. Only an unexpected error
 * does. So refusals are collected as results, and only a Throwable escapes.
 */
class BulkDeleteService
{
    /**
     * @template TModel of Model
     *
     * @param  iterable<TModel>  $models
     * @param  callable(TModel): void  $delete  the same single-delete logic
     * @param  callable(TModel): array{allowed: bool, reason: string|null}|null  $guard
     * @return array{deleted: int, skipped: array<int, string>}
     */
    public function run(iterable $models, callable $delete, ?callable $guard = null, ?User $user = null): array
    {
        $deleted = 0;
        $skipped = [];

        DB::transaction(function () use ($models, $delete, $guard, &$deleted, &$skipped) {
            foreach ($models as $model) {
                $label = $model->document_no ?? $model->name ?? '#'.$model->getKey();

                if ($guard) {
                    $state = $guard($model);

                    if (! $state['allowed']) {
                        $skipped[$label] = $state['reason'];

                        continue;
                    }
                }

                try {
                    $delete($model);
                    $deleted++;
                } catch (RuntimeException $e) {
                    // A refusal the domain raised on purpose — a lock, or a
                    // foreign key still pointing here. Report it and carry on.
                    $skipped[$label] = $e->getMessage();
                } catch (Throwable $e) {
                    // Anything else is unexpected, and Section 8b says the whole
                    // batch rolls back rather than leaving a partial result.
                    throw $e;
                }
            }
        });

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Section 8b's example message: "12 deleted, 3 skipped: already used in
     * sales." Distinct reasons are listed, so the report says what to do rather
     * than only how many failed.
     *
     * @param  array{deleted: int, skipped: array<string, string>}  $result
     */
    public function summarise(array $result): string
    {
        $deleted = trans_choice(
            '{0}Nothing deleted|{1}:count deleted|[2,*]:count deleted',
            $result['deleted'],
            ['count' => $result['deleted']],
        );

        if ($result['skipped'] === []) {
            return $deleted;
        }

        $reasons = collect($result['skipped'])->unique()->take(3)->implode(' ');

        return $deleted.', '.trans_choice(
            '{1}:count skipped:|[2,*]:count skipped:',
            count($result['skipped']),
            ['count' => count($result['skipped'])],
        ).' '.$reasons;
    }
}
