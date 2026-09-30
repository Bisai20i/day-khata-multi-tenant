<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen at the time of the fix: the User attributes that are hidden and
     * were copied verbatim into activity_logs.changes before
     * ActivityLogObserver started redacting them.
     *
     * @var list<string>
     */
    private const SECRET_KEYS = ['password', 'remember_token'];

    private const REDACTED = '[redacted]';

    /**
     * Replace password hashes and remember tokens already stored in User
     * change sets with the same marker the observer now writes. Only rows
     * that still hold a real value are touched, so a re-run is a no-op.
     */
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        DB::table('activity_logs')
            ->where('subject_type', 'App\\Models\\User')
            ->whereNotNull('changes')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $changes = json_decode((string) $row->changes, true);

                    if (! is_array($changes)) {
                        continue;
                    }

                    $dirty = false;
                    foreach (self::SECRET_KEYS as $key) {
                        if (array_key_exists($key, $changes) && $changes[$key] !== self::REDACTED) {
                            $changes[$key] = self::REDACTED;
                            $dirty = true;
                        }
                    }

                    if ($dirty) {
                        DB::table('activity_logs')->where('id', $row->id)->update(['changes' => json_encode($changes)]);
                    }
                }
            });
    }

    /**
     * Irreversible by design: the redacted secrets are gone.
     */
    public function down(): void
    {
        //
    }
};
