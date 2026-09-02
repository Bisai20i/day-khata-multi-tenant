<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A single generic observer, attached to a fixed list of financial models in
 * AppServiceProvider::boot() - deliberately never touches any of those
 * models' own files (see AppServiceProvider's docblock for why).
 */
class ActivityLogObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);

        // A bare touch() (or a save() that only bumped updated_at) is noise,
        // not a real change worth logging.
        if ($changes === []) {
            return;
        }

        $this->write($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted');
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function write(Model $model, string $action, ?array $changes = null): void
    {
        ActivityLog::create([
            // Explicit 'web' guard - this app's convention, see
            // AuthenticatedSessionController's docblock on guard ambiguity.
            // Null means system/console (a seeder, an artisan command, or
            // any write made without an authenticated tenant user).
            'user_id' => Auth::guard('web')->id(),
            'action' => $action,
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'description' => sprintf('%s #%s %s', class_basename($model), $model->getKey(), $action),
            'changes' => $changes,
        ]);
    }
}
