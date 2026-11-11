<?php

namespace App\Support\Branches;

use App\Models\Account;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Codes of the three system accounts the inter-branch postings use (plan 12,
 * BR17), and the guard that keeps manual entries out of them.
 *
 * - ASA25 "Inter-Branch Clearing" (Current Assets, balance sheet): the
 *   lines BranchBalancer appends so every branch of a voucher balances on
 *   its own (I1). One branch's balance is what it owes or is owed by the
 *   others, so it can sit on either side; the balance sheet totals assets as
 *   debit minus credit, so a credit balance there simply reduces assets.
 *   Across all branches it nets to zero (I3).
 * - INI23 "Stock Transferred to Branches" (Sales Accounts, trading credit)
 *   and EXE11 "Stock Received from Branches" (Purchase Accounts, trading
 *   debit): the sender's and receiver's side of a branch stock transfer
 *   (BR18). Filed directly under the two trading groups so
 *   AccountingReportController::tradingCodes() puts them in the trading
 *   account, and each branch's gross profit reflects the stock it sent or
 *   took in. Company-wide they cancel out (I3), so consolidated gross
 *   profit is unchanged by a transfer.
 *
 * The codes follow ChartOfAccountsSeeder's prefixes (ASA2x for the
 * directly-filed current assets, INI2x for the Sales Accounts group, EXE8 to
 * EXE1x for the Purchase Accounts group). All three are in
 * Account::SYSTEM_CODES, so they cannot be deleted, renamed, recoded or
 * moved, and code lookups stay stable.
 *
 * All three are CONTROL accounts: only the system may post to them. A
 * one-sided manual line in the clearing account, or in only one of the two
 * transfer accounts, breaks I3 and the year-end close refuses to run
 * (FiscalYear::assertBranchBooksCanClose()). So every manual entry point
 * rejects them up front via assertNotControlAccounts(), like
 * ManufacturingAccounts and HrAccounts do for theirs. The system's own
 * clearing lines (BranchBalancer, JournalVoucher::post() with autoBalance)
 * never pass through that guard.
 */
final class BranchAccounts
{
    /** Current Assets: what each branch owes or is owed by the other branches. */
    public const CLEARING_ACCOUNT_CODE = 'ASA25';

    /** Sales Accounts: the sending branch's trading credit for stock it transferred out. */
    public const STOCK_TRANSFERRED_CODE = 'INI23';

    /** Purchase Accounts: the receiving branch's trading debit for stock it took in. */
    public const STOCK_RECEIVED_CODE = 'EXE11';

    public const STOCK_TRANSFERRED_NAME = 'Stock Transferred to Branches';

    public const STOCK_RECEIVED_NAME = 'Stock Received from Branches';

    /**
     * @var list<string>
     */
    public const CONTROL_CODES = [self::CLEARING_ACCOUNT_CODE, self::STOCK_TRANSFERRED_CODE, self::STOCK_RECEIVED_CODE];

    /**
     * The exact name each control account is seeded or adopted with (the
     * inter-branch accounts migration accepts no other). Account::SYSTEM_CODES
     * freezes the name from then on, so an account carrying one of these codes
     * under a different name is a tenant's own account that held the code
     * before it was reserved.
     *
     * @var array<string, string>
     */
    public const CONTROL_NAMES = [
        self::CLEARING_ACCOUNT_CODE => 'Inter-Branch Clearing',
        self::STOCK_TRANSFERRED_CODE => self::STOCK_TRANSFERRED_NAME,
        self::STOCK_RECEIVED_CODE => self::STOCK_RECEIVED_NAME,
    ];

    /**
     * The error to show when the account found by a control code is not the
     * control account but some other account, or null when it is the real
     * one. Defence in depth: the inter-branch accounts migration moves a
     * tenant's own account off a reserved code ("{code}-N") and normalises
     * the control accounts' names, so this should not fire; it guards against
     * a clash introduced outside the app (a direct database edit or a restored
     * backup). The posting paths call this after their code lookup, so such a
     * clash stops the first inter-branch posting with a clear message instead
     * of silently posting system lines into someone's own account.
     */
    public static function codeConflictMessage(string $code, string $accountName): ?string
    {
        $expected = self::CONTROL_NAMES[$code] ?? null;

        if ($expected === null || $accountName === $expected) {
            return null;
        }

        return sprintf(
            'Entries between branches need the system account "%s" (code %s), but code %s is already used by your '
            .'account "%s". Contact support to give "%s" a different code; nothing has been posted.',
            $expected,
            $code,
            $code,
            $accountName,
            $accountName,
        );
    }

    /**
     * Throws when any of the given account ids is an inter-branch control
     * account. For manual journal vouchers, the cash/bank vouchers and the
     * other hand-picked account paths (see the class docblock).
     *
     * @param  iterable<int|string|null>  $accountIds
     *
     * @throws InvalidArgumentException
     */
    public static function assertNotControlAccounts(iterable $accountIds): void
    {
        $ids = collect($accountIds)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $control = Account::query()->whereIn('id', $ids)->whereIn('code', self::CONTROL_CODES)->pluck('name');

        if ($control->isNotEmpty()) {
            throw new InvalidArgumentException(
                '"'.$control->implode('", "').'" is posted only by the system (entries that span branches and branch '
                .'stock transfers), so the branches\' balances with each other always net to zero. Tag each line with '
                .'the branch it belongs to and the clearing lines are added for you; move stock between branches with '
                .'a stock transfer.'
            );
        }
    }

    /**
     * Whether the account id is one of the control accounts. For validation
     * rules that report a field error instead of throwing.
     */
    public static function isControlAccount(mixed $accountId): bool
    {
        if (! is_int($accountId) && ! (is_string($accountId) && ctype_digit($accountId))) {
            return false;
        }

        return Account::query()->whereKey((int) $accountId)->whereIn('code', self::CONTROL_CODES)->exists();
    }

    /**
     * Narrows an account query to the accounts a person may pick: everything
     * except the three control accounts. For the account pickers, so a form
     * never offers an account its submit would refuse. Grouped in its own
     * where() so an OR elsewhere in the query cannot bypass it, and the
     * whereNull() keeps the many accounts without a code (NOT IN alone drops
     * NULL rows in SQL).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function exclude(Builder $query, string $column = 'code'): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull($column)->orWhereNotIn($column, self::CONTROL_CODES));
    }

    /**
     * Validation rule twin of isControlAccount(): a field error instead of an
     * exception, for the hand-picked account fields of the capital purchase,
     * capital sale and fixed asset forms (and the other plain
     * `exists:accounts,id` fields). Wording matches AccountUnderHead's.
     */
    public static function notControlAccountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::isControlAccount($value)) {
                $fail('The selected :attribute is posted only by the system for inter-branch entries and cannot be chosen here.');
            }
        };
    }
}
