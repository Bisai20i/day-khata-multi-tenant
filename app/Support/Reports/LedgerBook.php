<?php

namespace App\Support\Reports;

use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFilter;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One account's ledger for a window inside one fiscal year, shared by the
 * Reports ledger (/reports/ledger) and the per-account statement
 * (/accounts/{account}/ledger).
 *
 * Trial Balance semantics, so a TB drill-down lands on the same figures:
 * the opening is this year's Opening Balance voucher lines plus this year's
 * lines dated before $from; the period is this year's lines inside
 * [$from, $to] without the Opening Balance voucher (the two halves are
 * disjoint, so a window starting on the year's first day cannot count the
 * carry-forward twice, audit P0-18); and BOTH halves leave out the P&L sweep
 * voucher, exactly as Trial Balance does.
 *
 * Optional project filter ($projectId): only lines tagged with that project,
 * in both halves. The Opening Balance voucher's lines carry no project, so a
 * project ledger opens with the project's own earlier postings only, the same
 * rule as the income statement's project mode.
 *
 * With $withDocuments (the ledger pages, PDF and Excel) every entry carries
 * its `document` link. With $withDocuments and $withParticulars (default on)
 * it also carries `particulars`: the other side of its voucher, i.e. the names
 * of the accounts on the opposite side of the same voucher. Cash Book / Bank
 * Book call with documents off and keep their narrower entry shape; the party
 * ledger turns particulars off for viewers without account-ledger access.
 *
 * The balance arithmetic itself lives in LedgerStatement.
 */
final class LedgerBook
{
    /**
     * @param  list<int>|null  $branchIds  BranchFilter::lineBranchIds(); null adds no predicate.
     * @param  int|null  $projectId  Only lines tagged with this project; null adds no predicate.
     * @param  bool  $withParticulars  Gates only the `particulars` key (names of the opposite accounts, read unrestricted by branch); it still needs $withDocuments, so Cash Book / Bank Book keep their shape. Pass false for callers whose viewer may not see other accounts' names.
     * @return array{entries: list<array<string, mixed>>, openingBalance: string, openingSide: string, closingBalance: string, closingSide: string, periodDebit: string, periodCredit: string}
     */
    public static function build(Account $account, FiscalYear $fiscalYear, string $from, string $to, ?array $branchIds = null, bool $includeSubAccounts = false, bool $withDocuments = true, ?int $projectId = null, bool $withParticulars = true): array
    {
        $accountIds = [(int) $account->id];

        if ($includeSubAccounts) {
            $accountIds = array_values(array_unique(array_merge($accountIds, $account->allSubAccountIds())));
        }

        $sweepVoucherIds = self::sweepVoucherIds($fiscalYear);

        $opening = self::openingBalance($accountIds, $fiscalYear, $from, $sweepVoucherIds, $branchIds, $projectId);

        $lines = JournalVoucherLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from, $to, $sweepVoucherIds) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($sweepVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $sweepVoucherIds))
                    ->where('voucher_type', '!=', VoucherType::OpeningBalance->value)
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to);
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->when($projectId !== null, fn (Builder $lines) => $lines->where('journal_voucher_lines.project_id', $projectId))
            ->with(['journalVoucher', 'account:id,name,code'])
            ->get()
            ->sortBy([['journalVoucher.date', 'asc'], ['id', 'asc']])
            ->values();

        // Branch-series numbers (BR20) print with their code; no query when
        // every voucher is in the shared series.
        JournalVoucher::loadSeriesBranches($lines->pluck('journalVoucher'));

        $documents = [];
        $particulars = [];
        $includeParticulars = $withDocuments && $withParticulars;

        if ($withDocuments && $lines->isNotEmpty()) {
            $voucherIds = $lines->pluck('journal_voucher_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $documents = LedgerDocuments::forVouchers($voucherIds);

            if ($includeParticulars) {
                $particulars = self::particulars($lines, $voucherIds, $accountIds);
            }
        }

        $rows = $lines->map(function (JournalVoucherLine $line) use ($account, $documents, $particulars, $includeParticulars): array {
            $voucher = $line->journalVoucher;
            $isSubAccount = (int) $line->account_id !== (int) $account->id;
            $subAccountTag = $isSubAccount && $line->account ? " [{$line->account->name}]" : '';

            $lineNarration = $line->narration ?? $voucher->narration;
            $voucherNarration = $voucher->narration;
            $mainNarration = $voucherNarration === null
                || trim((string) $voucherNarration) === ''
                || $voucherNarration === $lineNarration
                    ? null
                    : $voucherNarration;

            $document = $documents[(int) $voucher->id] ?? null;

            $row = [
                'date' => $voucher->date->toDateString(),
                'dateBs' => NepaliCalendar::formatBs($voucher->date),
                'voucherType' => $voucher->voucher_type->value,
                'voucherNumber' => $voucher->displayNumber(),
                'narration' => $lineNarration === null && $subAccountTag === '' ? null : $lineNarration.$subAccountTag,
                'mainNarration' => $mainNarration,
                'accountName' => $line->account?->name,
                'accountCode' => $line->account?->code,
                'isSubAccount' => $isSubAccount,
                'debit' => Money::of($line->debit)->toString(),
                'credit' => Money::of($line->credit)->toString(),
                'reference' => $document['reference'] ?? null,
                'document' => $document === null ? null : ['label' => $document['label'], 'url' => $document['url']],
            ];

            if ($includeParticulars) {
                $row['particulars'] = $particulars[(int) $line->id] ?? null;
            }

            return $row;
        })->values()->all();

        return LedgerStatement::build($opening->toString(), $rows);
    }

    /**
     * This year's Opening Balance voucher lines plus this year's lines dated
     * before $from on $accountIds, without the sweep vouchers, summed as
     * scaled integers so the database never hands back a float.
     *
     * @param  list<int>  $accountIds
     * @param  array<int, int>  $sweepVoucherIds
     * @param  list<int>|null  $branchIds
     */
    private static function openingBalance(array $accountIds, FiscalYear $fiscalYear, string $from, array $sweepVoucherIds, ?array $branchIds, ?int $projectId = null): Money
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $openingScaled = JournalVoucherLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from, $sweepVoucherIds) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($sweepVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $sweepVoucherIds))
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<', $from));
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->when($projectId !== null, fn (Builder $lines) => $lines->where('journal_voucher_lines.project_id', $projectId))
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->value('net_scaled');

        return Money::of(BigDecimal::of((int) $openingScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * The "other side" of each ledger line's voucher, as one printable text
     * per line (the reference system's Particulars column). For a debit line
     * the accounts credited in the same voucher, for a credit line the
     * accounts debited, each name once, in voucher order, outside the
     * ledger's own accounts (the account and, when included, its
     * sub-accounts). At most three names are listed, the rest as "+N more".
     * One query per chunk of vouchers, never one per line; the lines read
     * here are the whole voucher, so the text does not change with the branch
     * or project filter (account names are not branch data).
     *
     * @param  Collection<int, JournalVoucherLine>  $lines  The ledger's lines (journalVoucher loaded)
     * @param  list<int>  $voucherIds
     * @param  list<int>  $accountIds  The ledger's own accounts, left out of the text
     * @return array<int, string> Particulars keyed by ledger line id; absent when there is no other side
     */
    private static function particulars(Collection $lines, array $voucherIds, array $accountIds): array
    {
        /** @var array<int, array{debit: list<string>, credit: list<string>}> $sides */
        $sides = [];

        BranchContext::unrestricted(function () use ($voucherIds, $accountIds, &$sides): void {
            foreach (array_chunk($voucherIds, 500) as $chunk) {
                $others = JournalVoucherLine::query()
                    ->whereIn('journal_voucher_id', $chunk)
                    ->whereNotIn('account_id', $accountIds)
                    ->with('account:id,name')
                    ->orderBy('id')
                    ->get(['id', 'journal_voucher_id', 'account_id', 'debit', 'credit']);

                foreach ($others as $other) {
                    $name = $other->account?->name;

                    if ($name === null) {
                        continue;
                    }

                    $voucherId = (int) $other->journal_voucher_id;

                    if (! Money::of($other->debit)->isZero()) {
                        $sides[$voucherId]['debit'][] = $name;
                    }

                    if (! Money::of($other->credit)->isZero()) {
                        $sides[$voucherId]['credit'][] = $name;
                    }
                }
            }
        });

        $texts = [];

        foreach ($lines as $line) {
            $voucherId = (int) $line->journal_voucher_id;
            $names = ! Money::of($line->debit)->isZero()
                ? ($sides[$voucherId]['credit'] ?? [])
                : ($sides[$voucherId]['debit'] ?? []);
            $names = array_values(array_unique($names));

            if ($names === []) {
                continue;
            }

            $text = implode(', ', array_slice($names, 0, 3));

            if (count($names) > 3) {
                $text .= ' +'.(count($names) - 3).' more';
            }

            $texts[(int) $line->id] = $text;
        }

        return $texts;
    }

    /**
     * Ids of this year's P&L SWEEP vouchers - a copy of
     * AccountingReportController::sweepVoucherIds(), which Trial Balance
     * uses, so the ledger and the trial balance agree on a closed year.
     *
     * The sweep is a ClosingEntry voucher with no "Stock in Hand" line (the
     * two trading-stock ClosingEntry vouchers always carry one). Detection is
     * unrestricted (plan 12, section 5.7): which vouchers are the sweep is a
     * fact about the whole voucher, not the branches the viewer may see.
     *
     * Public so SubLedgerSummary leaves out exactly the same vouchers.
     *
     * @return array<int, int>
     */
    public static function sweepVoucherIds(FiscalYear $fiscalYear): array
    {
        $stockInHandId = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->value('id');

        return BranchContext::unrestricted(fn (): array => JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->when(
                $stockInHandId !== null,
                fn (Builder $query) => $query->whereDoesntHave('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHandId)),
            )
            ->pluck('id')
            ->all());
    }
}
