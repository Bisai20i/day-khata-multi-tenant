<?php

namespace App\Support\Branches;

use App\Models\Account;
use App\Models\JournalVoucherLine;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pairwise side of Inter-Branch Clearing (ASA25, plan 12, BR19): its
 * balance per (branch, counterparty branch), read from the
 * `counterparty_branch_id` every clearing line carries (BranchBalancer, the
 * two-step transfer vouchers, and since BR19 the year-end opening lines).
 *
 * A positive net for (A, B) is a debit in A's books against B: B owes A.
 * Every clearing pair is posted mirrored (A's debit toward B is B's credit
 * toward A), so (A, B) and (B, A) cancel once both halves are posted. A
 * transfer in transit is the one legitimate exception: its dispatch half is
 * in the sender's books and its receipt half is not yet in the receiver's.
 *
 * Lines without a counterparty (opening lines carried forward by a year-end
 * close before BR19) are kept in their own bucket, key UNATTRIBUTED, never
 * spread over pairs by guesswork.
 */
final class InterBranchBalances
{
    /** Bucket key of clearing lines that carry no counterparty branch. */
    public const UNATTRIBUTED = 0;

    /**
     * Net (debit - credit) on the clearing account per branch and
     * counterparty, in ONE grouped query. $voucherConstraint is the caller's
     * fiscal-year and date rule, applied inside whereHas('journalVoucher')
     * like BranchReportTotals::netByAccountAndBranch(). Scaled-integer SUM
     * for the same SQLite reason. BranchAccessScope applies: a restricted
     * user only ever gets their own branches' lines; the year-end close calls
     * this inside BranchContext::unrestricted().
     *
     * A counterparty equal to the line's own branch cannot be posted
     * (JournalVoucher::validateLines()); should one exist anyway it is folded
     * into the UNATTRIBUTED bucket rather than producing a line the posting
     * engine would refuse. Zero buckets are dropped.
     *
     * @param  Closure(Builder): mixed  $voucherConstraint
     * @return array<int, array<int, Money>> branch id => counterparty id (UNATTRIBUTED for none) => net
     */
    public static function netsByPair(int $clearingAccountId, Closure $voucherConstraint): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->where('journal_voucher_lines.account_id', $clearingAccountId)
            ->whereHas('journalVoucher', $voucherConstraint)
            ->selectRaw(
                "branch_id, counterparty_branch_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('branch_id', 'counterparty_branch_id')
            ->toBase()
            ->get();

        $scaled = [];

        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $counterpartyId = $row->counterparty_branch_id === null ? self::UNATTRIBUTED : (int) $row->counterparty_branch_id;

            if ($counterpartyId === $branchId) {
                $counterpartyId = self::UNATTRIBUTED;
            }

            $scaled[$branchId][$counterpartyId] = ($scaled[$branchId][$counterpartyId] ?? 0) + (int) $row->net_scaled;
        }

        $nets = [];

        foreach ($scaled as $branchId => $byCounterparty) {
            foreach ($byCounterparty as $counterpartyId => $netScaled) {
                if ($netScaled === 0) {
                    continue;
                }

                $nets[$branchId][$counterpartyId] = Money::of(BigDecimal::of($netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
            }
        }

        return $nets;
    }

    /**
     * Id of Inter-Branch Clearing by its protected code, or null when the
     * chart does not have it (tenant migrations not run) or when the code
     * belongs to one of the tenant's own accounts that held it before it
     * was reserved (BranchAccounts::codeConflictMessage(), BR22): that
     * account's balance is not inter-branch clearing, so callers treat the
     * tenant as having no clearing account rather than report it as one.
     */
    public static function clearingAccountId(): ?int
    {
        $account = Account::query()->where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->orderBy('id')->first(['id', 'name']);

        if ($account === null || BranchAccounts::codeConflictMessage(BranchAccounts::CLEARING_ACCOUNT_CODE, (string) $account->name) !== null) {
            return null;
        }

        return (int) $account->id;
    }

    /**
     * One branch's buckets in a stable order: UNATTRIBUTED first, then the
     * counterparties in $branchOrder (Main first, as the year-end close lists
     * branches), then any other counterparty by id.
     *
     * @param  array<int, Money>  $byCounterparty
     * @param  list<int>  $branchOrder
     * @return list<array{0: int, 1: Money}> [counterparty id or UNATTRIBUTED, net]
     */
    public static function ordered(array $byCounterparty, array $branchOrder): array
    {
        $position = array_flip($branchOrder);
        $keys = array_keys($byCounterparty);

        usort($keys, function (int $left, int $right) use ($position): int {
            $rank = fn (int $key): array => match (true) {
                $key === self::UNATTRIBUTED => [0, 0],
                isset($position[$key]) => [1, $position[$key]],
                default => [2, $key],
            };

            return $rank($left) <=> $rank($right);
        });

        return array_map(fn (int $key): array => [$key, $byCounterparty[$key]], $keys);
    }
}
