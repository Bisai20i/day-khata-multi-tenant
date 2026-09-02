<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per on-demand tenant database backup. The underlying file is
 * never stored under public/ - see BackupController and
 * routes/tenant-backups.php's docblock for the legacy security bug this
 * deliberately avoids.
 */
#[Fillable(['filename', 'disk', 'size_bytes', 'status', 'created_by'])]
class Backup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
