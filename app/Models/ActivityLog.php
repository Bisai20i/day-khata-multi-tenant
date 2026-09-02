<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single append-only audit-trail row, written exclusively by
 * ActivityLogObserver (see app/Providers/AppServiceProvider.php for which
 * models are observed). Nothing in the app should ever update or delete a
 * row here - read-only from the application's own perspective.
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'description', 'changes'])]
class ActivityLog extends Model
{
    /**
     * No updated_at column exists (this is an append-only log), and
     * created_at is set by the database itself (useCurrent()) rather than
     * Eloquent, but Eloquent still needs to know updated_at doesn't exist.
     */
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * The actor who triggered this activity. Null means system/console
     * (e.g. a seeder or an artisan command run without an authenticated
     * tenant user).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The model this activity happened to.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
