<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
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
        ]);
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
