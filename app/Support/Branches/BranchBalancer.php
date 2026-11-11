<?php

namespace App\Support\Branches;

use App\Models\Account;
use App\Support\Money\Money;
use Closure;

/**
 * Makes every branch of a voucher balance on its own (plan 12, invariant I1)
 * by appending Inter-Branch Clearing lines, hub and spoke around the
 * voucher's origin branch (section 5.3).
 *
 * Every branch other than the origin (a spoke) whose lines do not net to
 * zero gets one clearing line for the difference, with
 * counterparty_branch_id set to the origin, and the origin gets the mirror
 * line with counterparty_branch_id set to that spoke. Spokes are taken in
 * the order they first appear in the lines, so the result is deterministic.
 * Once every spoke balances, the origin balances too because the voucher as
 * a whole does (callers check the total first).
 *
 * Worked example from the plan: origin A receives 100 against branch B's
 * invoice. In: A Dr Cash 100, B Cr Customer 100. B nets to Cr 100, so B
 * gets Dr Clearing 100 (counterparty A) and A gets Cr Clearing 100
 * (counterparty B). A's cash rose, B's receivable fell, and the clearing
 * balances say A owes B.
 *
 * All amounts are exact Money; a voucher whose lines already balance per
 * branch (every single-branch voucher) comes back unchanged and never
 * touches the clearing account.
 */
final class BranchBalancer
{
    /**
     * Name of the clearing account. Display only (messages, the seeder and
     * the BR17 data migration): the account is looked up by its protected
     * code, BranchAccounts::CLEARING_ACCOUNT_CODE, which is in
     * Account::SYSTEM_CODES so neither the code nor this name can change.
     */
    public const CLEARING_ACCOUNT_NAME = 'Inter-Branch Clearing';

    public const CLEARING_NARRATION = 'Inter-branch clearing';

    /**
     * @param  list<array{account_id: int, debit: string, credit: string, narration: string|null, branch_id: int, counterparty_branch_id: int|null}>  $lines  Normalised lines (JournalVoucher::validateLines()), with a balanced total.
     * @param  (Closure(): int)|null  $clearingAccountId  Resolves the clearing account; defaults to clearingAccountId(). Called at most once, and only when a clearing line is needed.
     * @return list<array{account_id: int, debit: string, credit: string, narration: string|null, branch_id: int, counterparty_branch_id: int|null}>
     */
    public static function balance(array $lines, int $originBranchId, ?Closure $clearingAccountId = null): array
    {
        $clearingLines = [];
        $accountId = null;

        foreach (self::imbalances($lines) as $branchId => $net) {
            if ($branchId === $originBranchId) {
                continue;
            }

            $accountId ??= $clearingAccountId !== null ? $clearingAccountId() : self::clearingAccountId();
            $amount = $net->abs()->toString();

            // A spoke with surplus debit needs a credit, and the origin the
            // matching debit; a spoke with surplus credit, the other way round.
            $spokeIsDebited = $net->isNegative();

            $clearingLines[] = self::clearingLine($accountId, $branchId, $originBranchId, $amount, $spokeIsDebited);
            $clearingLines[] = self::clearingLine($accountId, $originBranchId, $branchId, $amount, ! $spokeIsDebited);
        }

        return [...$lines, ...$clearingLines];
    }

    /**
     * Net (debit minus credit) of every branch whose lines do not balance,
     * keyed by branch id in first-appearance order. Empty when each branch
     * balances.
     *
     * @param  iterable<array{debit: string, credit: string, branch_id: int}>  $lines
     * @return array<int, Money>
     */
    public static function imbalances(iterable $lines): array
    {
        $nets = [];

        foreach ($lines as $line) {
            $branchId = $line['branch_id'];
            $nets[$branchId] = ($nets[$branchId] ?? Money::zero())
                ->plus(Money::of($line['debit']))
                ->minus(Money::of($line['credit']));
        }

        return array_filter($nets, fn (Money $net): bool => ! $net->isZero());
    }

    /**
     * The clearing account id, by its protected code. Uncached, like
     * ManufacturingAccounts::id(): a stale id could post to another
     * tenant's account.
     *
     * @throws BranchRuleViolation When the account does not exist, or the code belongs to a tenant's own account (BranchAccounts::codeConflictMessage()).
     */
    public static function clearingAccountId(): int
    {
        $account = Account::query()->where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->first(['id', 'name']);

        if ($account === null) {
            throw BranchRuleViolation::clearingAccountMissing(self::CLEARING_ACCOUNT_NAME, BranchAccounts::CLEARING_ACCOUNT_CODE);
        }

        $conflict = BranchAccounts::codeConflictMessage(BranchAccounts::CLEARING_ACCOUNT_CODE, (string) $account->name);

        if ($conflict !== null) {
            throw new BranchRuleViolation($conflict);
        }

        return (int) $account->id;
    }

    /**
     * @return array{account_id: int, debit: string, credit: string, narration: string, branch_id: int, counterparty_branch_id: int}
     */
    private static function clearingLine(int $accountId, int $branchId, int $counterpartyBranchId, string $amount, bool $isDebit): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $isDebit ? $amount : Money::zero()->toString(),
            'credit' => $isDebit ? Money::zero()->toString() : $amount,
            'narration' => self::CLEARING_NARRATION,
            'branch_id' => $branchId,
            'counterparty_branch_id' => $counterpartyBranchId,
        ];
    }
}
