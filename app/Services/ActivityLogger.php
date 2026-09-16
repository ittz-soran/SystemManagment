<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Notifications;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 4 / Section 8: every login, create, update and delete is recorded,
 * and every edit stores the full previous version in `old_values` JSON.
 */
class ActivityLogger
{
    public function log(
        string $action,
        string $module,
        ?int $recordId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?User $user = null,
        ?string $tier = null,
    ): ?ActivityLog {
        $user ??= auth()->user();

        // activity_logs.user_id is a required FK — an unattributable action is
        // not worth a row that would break the audit's meaning.
        if (! $user) {
            return null;
        }

        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'description' => $description,
            'old_values' => $oldValues,
            'ip_address' => request()->ip(),

            /*
             * How loudly this entry is worth saying, decided here and stored,
             * so the bell can find the few rows that matter with an index
             * instead of reading a thousand and classifying them in PHP.
             *
             * An override only for the one case a rule cannot see: whether a
             * sign-in came from a familiar address — see logSignIn().
             */
            'tier' => $tier ?? Notifications::tierFor($action, $module),
        ]);
    }

    /**
     * Somebody signed in, recorded with the one thing that makes it worth
     * telling them: whether the address was theirs.
     *
     * Its own method rather than a branch in the Login listener, because the
     * question "is this worth a bell" belongs beside every other answer to it
     * and not in a provider that wires events together.
     */
    public function logSignIn(User $user): ?ActivityLog
    {
        $ip = request()->ip();
        $tier = Notifications::signInTier($user, $ip);

        return $this->log(
            action: 'login',
            module: 'auth',
            recordId: $user->getKey(),
            description: $tier === Notifications::ALERT
                ? __('Signed in from an address this account has not used before')
                : __('Logged in'),
            user: $user,
            tier: $tier,
        );
    }

    /** Convenience for the common case: an action against one model. */
    public function logModel(string $action, Model $model, ?string $description = null, ?array $oldValues = null): ?ActivityLog
    {
        return $this->log(
            action: $action,
            module: $this->moduleFor($model),
            recordId: $model->getKey(),
            description: $description ?? $this->describe($action, $model),
            oldValues: $oldValues,
        );
    }

    private function moduleFor(Model $model): string
    {
        return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly(class_basename($model)));
    }

    /**
     * Section 9b: "Name things by what Soran controls, never by how the system
     * is built." So the description says what happened, not which table moved.
     */
    private function describe(string $action, Model $model): string
    {
        $label = $model->document_no
            ?? $model->name
            ?? $model->title
            ?? '#'.$model->getKey();

        $verb = match ($action) {
            'create' => __('Created'),
            'update' => __('Updated'),
            'delete' => __('Deleted'),
            'restore' => __('Restored'),
            default => __('Changed'),
        };

        return $verb.' '.class_basename($model).' '.$label;
    }
}
