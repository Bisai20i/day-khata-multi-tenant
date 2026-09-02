<?php

namespace App\Console\Commands;

use App\Enums\FiscalYearStatus;
use App\Enums\TenantStatus;
use App\Models\FiscalYear;
use App\Models\Tenant;
use App\Models\User;
use App\Support\NepaliCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Rolls every active tenant onto its next Nepali (Bikram Sambat) fiscal year
 * once the current one has run out, mirroring the legacy day_khata app's
 * `fiscalyear:autostart` command (`../day_khata/app/Console/Commands/AutoStartFiscalYear.php`).
 *
 * Nepal's official fiscal year runs Shrawan 1 through the following Ashad's
 * last day (BS month 4 -> BS month 3 of the next BS year) - three months
 * offset from the BS calendar year itself (Baishak 1), which legacy's
 * command computes for and this one matches. Run daily; it's a no-op every
 * day except the one where "today" first lands on or after the expected
 * Shrawan-1 start of a period the tenant's currently open fiscal year
 * doesn't already cover.
 *
 * Unlike legacy, this never bootstraps a tenant's very first fiscal year out
 * of thin air - a tenant with no fiscal year at all has nothing to roll
 * forward from (no dates to infer, no prior year's balances to carry), so
 * that stays a deliberate action taken by the tenant's admin via
 * FiscalYearController::store().
 */
class AutoStartFiscalYear extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fiscal-year:auto-start';

    /**
     * @var string
     */
    protected $description = 'Roll every active tenant onto its next Bikram Sambat fiscal year (Shrawan 1) once the currently open year has passed';

    public function handle(): int
    {
        $today = NepaliCalendar::adToBs(Carbon::today());

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->each(function (Tenant $tenant) use ($today): void {
                try {
                    $tenant->run(fn () => $this->rollTenant($tenant, $today));
                } catch (Throwable $e) {
                    $this->error("Tenant {$tenant->getTenantKey()}: fiscal year auto-start failed - {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }

    /**
     * @param  array{year: int, month: int, day: int}  $todayBs
     */
    private function rollTenant(Tenant $tenant, array $todayBs): void
    {
        // Nepal's fiscal year starts on Shrawan 1 (BS month 4). If we're
        // still in Baishak-Ashad (BS months 1-3), that boundary belongs to
        // last BS year's fiscal year, not this one.
        $expectedStartBsYear = $todayBs['month'] >= 4 ? $todayBs['year'] : $todayBs['year'] - 1;
        $expectedStart = NepaliCalendar::bsToAd($expectedStartBsYear, 4, 1);

        $openFiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($openFiscalYear === null) {
            // Nothing to roll forward from - the tenant hasn't bootstrapped
            // its first fiscal year yet.
            return;
        }

        if ($openFiscalYear->start_date->toDateString() >= $expectedStart->toDateString()) {
            // Already open for the current (or a future) period.
            return;
        }

        $actor = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'admin'))->first();

        if ($actor === null) {
            $this->warn("Tenant {$tenant->getTenantKey()}: no admin user to act as - skipped.");

            return;
        }

        $nextEnd = NepaliCalendar::bsToAd($expectedStartBsYear + 1, 4, 1)->subDay();
        $name = sprintf('%d/%02d', $expectedStartBsYear, ($expectedStartBsYear + 1) % 100);

        try {
            $next = FiscalYear::create([
                'name' => $name,
                'start_date' => $expectedStart->toDateString(),
                'end_date' => $nextEnd->toDateString(),
                'status' => FiscalYearStatus::Closed,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error("Tenant {$tenant->getTenantKey()}: could not create fiscal year {$name} - {$e->getMessage()}");

            return;
        }

        $openFiscalYear->close($next, $actor);

        $this->info("Tenant {$tenant->getTenantKey()}: rolled over to fiscal year {$name}.");
    }
}
