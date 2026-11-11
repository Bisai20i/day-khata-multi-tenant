<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Branches\Audit\BranchAuditResult;
use App\Support\Branches\BranchAudit;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Read-only multi-branch audit (plan 12, BR23), for the pilot dry run and
 * before switching a tenant on: runs BranchAudit for every tenant that has
 * the `branches` module (or the tenants named with --tenant, module or
 * not, e.g. a copy of a real tenant before the module is switched on) and
 * prints one PASS / WARN / FAIL line per check. Nothing is posted, locked
 * or written. Exit code 1 when any check FAILs or a tenant cannot be read;
 * warnings alone exit 0.
 *
 * Each tenant is audited between tenancy()->initialize() and a
 * tenancy()->end() in `finally` (not Tenant::run(), which does not restore
 * the central context when the callback throws), so one tenant whose
 * database is missing cannot leave the next tenant's audit pointed at the
 * wrong connection.
 */
class BranchesAudit extends Command
{
    /**
     * @var string
     */
    protected $signature = 'branches:audit
        {--tenant=* : Audit only these tenant ids (even when the branches module is off)}
        {--details : List the offending records of each check (also with -v)}
        {--json : Print the results as JSON}';

    /**
     * @var string
     */
    protected $description = 'Read-only: check the multi-branch invariants (balanced branches, clearing, stock, stores, documents, numbering) of each tenant';

    /**
     * @var string
     */
    protected $help = <<<'HELP'
        Runs every multi-branch check against each tenant's books and prints PASS, WARN or FAIL per check.
        Nothing is written: every tenant's reads run in one read-only transaction, so they all see one
        consistent snapshot even while people keep posting. Still, run it on a quiet system or on a copy
        of the database where you can: a long audit holds that snapshot open for its whole run.
        HELP;

    public function handle(): int
    {
        $requested = array_values(array_filter(array_map('strval', (array) $this->option('tenant')), fn (string $id): bool => $id !== ''));
        $reports = [];
        $skipped = 0;
        $found = [];

        Tenant::query()
            ->when($requested !== [], fn ($query) => $query->whereIn('id', $requested))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($requested, &$reports, &$skipped, &$found): void {
                $found[] = (string) $tenant->getTenantKey();
                $moduleOn = $tenant->hasModule('branches');

                if ($requested === [] && ! $moduleOn) {
                    $skipped++;

                    return;
                }

                $reports[] = $this->auditTenant($tenant, $moduleOn);
            });

        $notFound = array_values(array_diff($requested, $found));

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'tenants' => array_map(fn (array $report): array => [
                    ...$report,
                    'results' => array_map(fn (BranchAuditResult $result): array => $result->toArray(), $report['results']),
                ], $reports),
                'skipped_without_module' => $skipped,
                'not_found' => $notFound,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            foreach ($reports as $report) {
                $this->printReport($report);
            }

            foreach ($notFound as $id) {
                $this->error('Tenant '.OutputFormatter::escape($id).' not found.');
            }

            $this->printSummary($reports, $skipped);
        }

        $failed = $notFound !== [] || collect($reports)->contains(fn (array $report): bool => BranchAudit::hasFailures($report['results']));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{tenant_id: string, company: ?string, module_enabled: bool, results: list<BranchAuditResult>}
     */
    private function auditTenant(Tenant $tenant, bool $moduleOn): array
    {
        try {
            tenancy()->initialize($tenant);
            $results = BranchAudit::run();
        } catch (Throwable $e) {
            $results = [BranchAuditResult::fail('tenant.database', '-', 'Tenant database readable', 'Could not audit: '.$e->getMessage())];
        } finally {
            tenancy()->end();
        }

        return [
            'tenant_id' => (string) $tenant->getTenantKey(),
            'company' => $tenant->company_name,
            'module_enabled' => $moduleOn,
            'results' => $results,
        ];
    }

    /**
     * Text mode. Branch, company, item and account names are the tenant's
     * own text, so everything but the status labels goes through
     * OutputFormatter::escape(): a name containing "<error>" must print as
     * typed, not restyle (or break) the report.
     *
     * @param  array{tenant_id: string, company: ?string, module_enabled: bool, results: list<BranchAuditResult>}  $report
     */
    private function printReport(array $report): void
    {
        $detailed = $this->option('details') || $this->getOutput()->isVerbose();

        $this->newLine();
        $this->line('<options=bold>Tenant '.OutputFormatter::escape($report['tenant_id']).'</> | '
            .OutputFormatter::escape($report['company'] ?? '(no company name)')
            .($report['module_enabled'] ? '' : ' | branches module OFF'));

        foreach ($report['results'] as $result) {
            $label = match ($result->status) {
                BranchAuditResult::FAIL => '<error>FAIL</error>',
                BranchAuditResult::WARN => '<comment>WARN</comment>',
                default => '<info>PASS</info>',
            };
            $invariant = $result->invariant === '-' ? '' : OutputFormatter::escape("[{$result->invariant}]").' ';

            $this->line("  {$label} {$invariant}".OutputFormatter::escape("{$result->title}: {$result->message}"));

            if (! $detailed || $result->items === []) {
                continue;
            }

            foreach ($result->items as $item) {
                $this->line('         - '.OutputFormatter::escape($item));
            }

            if ($result->count > count($result->items)) {
                $this->line('         ... first '.count($result->items)." of {$result->count} shown");
            }
        }

        $fails = count(array_filter($report['results'], fn (BranchAuditResult $result): bool => $result->isFailure()));
        $warns = count(array_filter($report['results'], fn (BranchAuditResult $result): bool => $result->isWarning()));
        $passes = count($report['results']) - $fails - $warns;

        $hasItems = collect($report['results'])->contains(fn (BranchAuditResult $result): bool => $result->items !== []);

        $this->line("  {$passes} pass, {$warns} warn, {$fails} fail".($detailed || ! $hasItems ? '' : ' (run with --details to list the records)'));
    }

    /**
     * @param  list<array{tenant_id: string, company: ?string, module_enabled: bool, results: list<BranchAuditResult>}>  $reports
     */
    private function printSummary(array $reports, int $skipped): void
    {
        $failing = 0;
        $warning = 0;

        foreach ($reports as $report) {
            if (BranchAudit::hasFailures($report['results'])) {
                $failing++;
            } elseif (collect($report['results'])->contains(fn (BranchAuditResult $result): bool => $result->isWarning())) {
                $warning++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d tenant(s) audited: %d with failures, %d with warnings only, %d clean.%s',
            count($reports),
            $failing,
            $warning,
            count($reports) - $failing - $warning,
            $skipped > 0 ? " {$skipped} tenant(s) without the branches module skipped." : '',
        ));
    }
}
