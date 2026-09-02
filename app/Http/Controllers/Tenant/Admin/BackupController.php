<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Per-tenant on-demand database backup. The whole route group is already
 * gated by role:admin (see routes/tenant-backups.php) - that's the only
 * auth check this controller needs. The backup file always lives on the
 * private "local" disk (storage/app/private, never public/) under
 * backups/{tenant_id}/{filename}; download/destroy always rebuild that path
 * from the stored filename column plus the CURRENT tenant id, never from
 * raw request input, so a path can't be escaped.
 *
 * Legacy (day_khata's databasebackupcontroller.php) stored backups under
 * public_path('backup/') - directly web-reachable with no auth at all,
 * since anything under public/ is served straight by the webserver. Do not
 * repeat that: this module never writes under public_path().
 */
class BackupController extends Controller
{
    private const DISK = 'local';

    public function index(): Response
    {
        return Inertia::render('Tenant/Admin/Backups/Index', [
            'backups' => Backup::query()
                ->with('creator:id,name')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $backup = $this->performBackup($request->user());

        if ($backup->status === 'failed') {
            return back()->withErrors(['backup' => 'The backup failed - see the new "failed" entry in the list for a record of the attempt.']);
        }

        return redirect()->route('tenant.backups.index')->with('status', 'Backup created.');
    }

    /**
     * Takes a raw id rather than an implicit {Backup} route binding
     * deliberately: implicit binding resolves inside the SubstituteBindings
     * middleware, which runs before this route's own `role:admin` middleware
     * in the pipeline - a missing id would 404 before the role check ever
     * ran, leaking route existence to non-admins instead of a clean 403.
     */
    public function download(int $backup): StreamedResponse
    {
        $backup = Backup::findOrFail($backup);
        $disk = Storage::disk($backup->disk);
        $path = $this->storagePath($backup->filename);

        if ($backup->status !== 'completed' || ! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path, $backup->filename);
    }

    public function destroy(int $backup): RedirectResponse
    {
        $backup = Backup::findOrFail($backup);
        Storage::disk($backup->disk)->delete($this->storagePath($backup->filename));
        $backup->delete();

        return redirect()->route('tenant.backups.index')->with('status', 'Backup deleted.');
    }

    /**
     * Dumps the current tenant's database (SQLite: a plain file copy; MySQL/
     * MariaDB: a mysqldump shell-out) and records a Backup row either way -
     * on failure the row is kept with status "failed" (no file) so a failed
     * attempt is still visible in the list, not silently swallowed.
     */
    private function performBackup(User $actor): Backup
    {
        $driver = DB::connection()->getConfig('driver');
        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $filename = 'backup_'.now()->format('Y_m_d_His').'_'.Str::random(6).'.'.$extension;
        $disk = Storage::disk(self::DISK);
        $path = $this->storagePath($filename);

        try {
            match ($driver) {
                'sqlite' => $this->dumpSqlite($disk, $path),
                'mysql', 'mariadb' => $this->dumpMysql($disk, $path),
                default => throw new RuntimeException("Backups are not supported for the \"{$driver}\" database driver."),
            };

            return Backup::create([
                'filename' => $filename,
                'disk' => self::DISK,
                'size_bytes' => $disk->size($path),
                'status' => 'completed',
                'created_by' => $actor->id,
            ]);
        } catch (Throwable $e) {
            report($e);

            if ($disk->exists($path)) {
                $disk->delete($path);
            }

            return Backup::create([
                'filename' => $filename,
                'disk' => self::DISK,
                'size_bytes' => null,
                'status' => 'failed',
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * SQLite has no separate server process to dump from - the whole
     * database already IS one file, so backing it up is a plain copy of
     * the tenant connection's own database file.
     */
    private function dumpSqlite(Filesystem $disk, string $path): void
    {
        $sourcePath = DB::connection()->getConfig('database');

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new RuntimeException('The tenant SQLite database file could not be located.');
        }

        $disk->put($path, file_get_contents($sourcePath));
    }

    /**
     * Shells out to mysqldump using the tenant connection's own
     * credentials (stancl/tenancy swaps config('database.connections.tenant')
     * to the current tenant's own database before this ever runs - see
     * DatabaseTenancyBootstrapper/DatabaseConfig::connection()). The
     * password is passed via the MYSQL_PWD environment variable rather than
     * a command-line flag, so it never appears in the process list.
     */
    private function dumpMysql(Filesystem $disk, string $path): void
    {
        $config = DB::connection()->getConfig();
        $disk->makeDirectory(dirname($path));
        $absolutePath = $disk->path($path);

        $result = Process::env(['MYSQL_PWD' => $config['password'] ?? ''])
            ->run([
                env('MYSQLDUMP_PATH', 'mysqldump'),
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? '3306'),
                '--user='.($config['username'] ?? ''),
                '--single-transaction',
                '--routines',
                '--triggers',
                '--result-file='.$absolutePath,
                $config['database'],
            ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }

        if (! $disk->exists($path) || $disk->size($path) === 0) {
            throw new RuntimeException('The backup file was not generated successfully.');
        }
    }

    /**
     * Scoped by the CURRENT tenant only (tenant('id') resolves from the
     * already-initialized tenancy context, never from request input), so a
     * Backup row from one tenant's database can never resolve to another
     * tenant's file even if ids collided.
     */
    private function storagePath(string $filename): string
    {
        return 'backups/'.tenant('id').'/'.$filename;
    }
}
