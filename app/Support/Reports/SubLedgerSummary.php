<?php

namespace App\Support\Reports;

use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
use App\Support\Branches\BranchFilter;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opening / Debit / Credit / Closing of every sub-ledger of one account (the
 * reference system's "sub ledger summary"), for the same window and with the
 * same semantics as LedgerBook, so the totals tie to the parent's own ledger
 * with "include sub-ledgers" on:
 *
 * - opening: this year's Opening Balance voucher lines plus this year's lines
 *   dated before $from;
 * - period: this year's lines inside [$from, $to], without the Opening Balance
 *   voucher;
 * - both halves leave out the P&L sweep voucher (LedgerBook::sweepVoucherIds());
 * - the branch filter and the optional project filter narrow every line.
 *
 * One row per direct sub-account, rolled up over its own sub-accounts. When the
 * parent account carries postings of its own, an extra first row holds them
 * ("own postings"), so the total row equals the parent ledger including its
 * sub-ledgers. Two grouped queries read every line; the arithmetic is Money.
 *
 * @phpstan-type SummaryRow array{id: int|null, code: string|null, name: string, own: bool, openingBalance: string, openingSide: string, debit: string, credit: string, closingBalance: string, closingSide: string}
 * @phpstan-type Summary array{rows: list<SummaryRow>, total: SummaryRow|null}
 */
final class SubLedgerSummary
{
    /**
     * @param  list<int>|null  $branchIds  BranchFilter::lineBranchIds(); null adds no predicate.
     * @param  int|null  $projectId  Only lines tagged with this project; null adds no predicate.
     * @return Summary
     */
    public static function build(Account $account, FiscalYear $fiscalYear, string $from, string $to, ?array $branchIds = null, ?int $projectId = null): array
    {
        $children = $account->subAccounts()->orderBy('code')->orderBy('name')->get(['id', 'code', 'name']);

        if ($children->isEmpty()) {
            return ['rows' => [], 'total' => null];
        }

        $childrenOf = Account::query()
            ->whereNotNull('parent_account_id')
            ->get(['id', 'parent_account_id'])
            ->groupBy(fn (Account $row): int => (int) $row->parent_account_id)
            ->map(fn ($group): array => $group->map(fn (Account $row): int => (int) $row->id)->all())
            ->all();

        // The account itself, then every direct child with its subtree.
        $groups = [['id' => null, 'code' => $account->code, 'name' => $account->name.' (own postings)', 'own' => true, 'ids' => [(int) $account->id]]];

        foreach ($children as $child) {
            $groups[] = [
                'id' => (int) $child->id,
                'code' => $child->code,
                'name' => $child->name,
                'own' => false,
                'ids' => self::subtree((int) $child->id, $childrenOf),
            ];
        }

        $allIds = array_values(array_unique(array_merge(...array_column($groups, 'ids'))));
        $sweepVoucherIds = LedgerBook::sweepVoucherIds($fiscalYear);

        $opening = self::scaledByAccount($allIds, $fiscalYear, $branchIds, $projectId, function (Builder $query) use ($sweepVoucherIds, $from) {
            $query->when($sweepVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $sweepVoucherIds))
                ->where(fn (Builder $q) => $q
                    ->where('voucher_type', VoucherType::OpeningBalance->value)
                    ->orWhereDate('date', '<', $from));
        });
        $period = self::scaledByAccount($allIds, $fiscalYear, $branchIds, $projectId, function (Builder $query) use ($sweepVoucherIds, $from, $to) {
            $query->when($sweepVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $sweepVoucherIds))
                ->where('voucher_type', '!=', VoucherType::OpeningBalance->value)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to);
        });

        $rows = [];
        $openings = [];
        $debits = [];
        $credits = [];

        foreach ($groups as $group) {
            $groupOpening = Money::sum(array_map(fn (int $id): Money => ($opening[$id]['debit'] ?? Money::zero())->minus($opening[$id]['credit'] ?? Money::zero()), $group['ids']));
            $groupDebit = Money::sum(array_map(fn (int $id): Money => $period[$id]['debit'] ?? Money::zero(), $group['ids']));
            $groupCredit = Money::sum(array_map(fn (int $id): Money => $period[$id]['credit'] ?? Money::zero(), $group['ids']));

            // The parent's own row only appears when it has something to show.
            if ($group['own'] && $groupOpening->isZero() && $groupDebit->isZero() && $groupCredit->isZero()) {
                continue;
            }

            $rows[] = self::row($group['id'], $group['code'], $group['name'], $group['own'], $groupOpening, $groupDebit, $groupCredit);
            $openings[] = $groupOpening;
            $debits[] = $groupDebit;
            $credits[] = $groupCredit;
        }

        $total = self::row(null, null, 'Total', false, Money::sum($openings), Money::sum($debits), Money::sum($credits));

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  array<int, list<int>>  $childrenOf  Account id => direct sub-account ids
     * @return list<int> The account and every nested sub-account, each once
     */
    private static function subtree(int $accountId, array $childrenOf): array
    {
        $ids = [];
        $queue = [$accountId];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $ids, true)) {
                continue;
            }

            $ids[] = $current;

            foreach ($childrenOf[$current] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Debit and credit totals per account over the lines whose voucher matches
     * $voucherScope inside this fiscal year, summed as scaled integers so the
     * database never hands back a float.
     *
     * @param  list<int>  $accountIds
     * @param  list<int>|null  $branchIds
     * @param  callable(Builder): void  $voucherScope
     * @return array<int, array{debit: Money, credit: Money}>
     */
    private static function scaledByAccount(array $accountIds, FiscalYear $fiscalYear, ?array $branchIds, ?int $projectId, callable $voucherScope): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $voucherScope) {
                $query->where('fiscal_year_id', $fiscalYear->id);
                $voucherScope($query);
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->when($projectId !== null, fn (Builder $lines) => $lines->where('journal_voucher_lines.project_id', $projectId))
            ->selectRaw(
                "account_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) as debit_scaled,
                 COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as credit_scaled"
            )
            ->groupBy('account_id')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->account_id] = [
                'debit' => self::fromScaled((int) $row->debit_scaled),
                'credit' => self::fromScaled((int) $row->credit_scaled),
            ];
        }

        return $totals;
    }

    private static function fromScaled(int $scaled): Money
    {
        return Money::of(BigDecimal::of($scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * @return SummaryRow
     */
    private static function row(?int $id, ?string $code, string $name, bool $own, Money $opening, Money $debit, Money $credit): array
    {
        $closing = $opening->plus($debit)->minus($credit);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'own' => $own,
            'openingBalance' => $opening->toString(),
            'openingSide' => LedgerStatement::side($opening),
            'debit' => $debit->toString(),
            'credit' => $credit->toString(),
            'closingBalance' => $closing->toString(),
            'closingSide' => LedgerStatement::side($closing),
        ];
    }
}
