<?php

namespace App\Support\Hr;

use App\Http\Controllers\Tenant\Sales\SaleController;
use App\Models\Account;
use App\Support\Branches\BranchAccounts;
use App\Support\Manufacturing\ManufacturingAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Codes of the system accounts HR payroll posts to, and an uncached
 * code -> id lookup (uncached for the same reason as ManufacturingAccounts::id():
 * test tenants are recreated under the same id, so a cached id could point at
 * another tenant's account).
 *
 * Salary Payable and Staff Advances Receivable are CONTROL accounts: the
 * per-staff balance lives in the HR tables (payroll lines, payments,
 * advances), and StaffBalances::reconciliation() checks the two agree. A
 * hand-posted entry into either would split them, so every manual entry point
 * rejects them: journal vouchers and the cash/bank vouchers
 * (JournalVoucherController, via assertNotControlAccounts()), the HR
 * settlement path (settlementAccountId(), so a payment or advance cannot name
 * one as its "bank"), and the opening balance import
 * (AccountController::OPENING_BALANCE_BLOCKED_CODES lists both codes).
 */
class HrAccounts
{
    /** Current Liabilities: salary accrued and not yet paid (the Balance Sheet "Payable"). */
    public const SALARY_PAYABLE = 'LIA22';

    /** Current Assets: advances given to staff and not yet recovered (the Balance Sheet "Receivable"). */
    public const STAFF_ADVANCES_RECEIVABLE = 'ASA24';

    public const SALARIES_AND_WAGES = 'EXE25';

    public const STAFF_BONUS = 'EXE26';

    public const STAFF_ALLOWANCES = 'EXE27';

    /**
     * @var list<string>
     */
    public const CONTROL_CODES = [self::SALARY_PAYABLE, self::STAFF_ADVANCES_RECEIVABLE];

    /**
     * The pay components every tenant starts with, in display order.
     *
     * @var list<array{code: string, name: string, account: string}>
     */
    public const STARTER_COMPONENTS = [
        ['code' => 'SALARY', 'name' => 'Salary', 'account' => self::SALARIES_AND_WAGES],
        ['code' => 'BONUS', 'name' => 'Bonus', 'account' => self::STAFF_BONUS],
        ['code' => 'ALLOWANCE', 'name' => 'Allowance', 'account' => self::STAFF_ALLOWANCES],
    ];

    /**
     * Throws when any of the given account ids is an HR control account. For
     * manual journal vouchers, the cash/bank vouchers and the HR settlement
     * path (the opening balance import blocks the same codes by its own list).
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
                '"'.$control->implode('", "').'" can only be changed from the HR module (payroll runs, salary payments, '
                .'staff advances), so its balance always matches the staff ledger. Use one of those instead.'
            );
        }
    }

    /**
     * @throws RuntimeException when the account does not exist yet
     */
    public static function id(string $code): int
    {
        $id = Account::where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("System account {$code} is missing. Run the tenant migrations to add the HR accounts.");
        }

        return (int) $id;
    }

    /**
     * The cash / bank accounts offered when paying salary or giving an advance:
     * assets, minus the HR and inter-branch control accounts and minus party ledgers (customer,
     * supplier, agent), which are the other side of a transaction and are
     * refused on save by AccountUnderHead.
     *
     * @return Collection<int, Account>
     */
    public static function paymentSourceAccounts(): Collection
    {
        return Account::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('group.accountHead', fn (Builder $head) => $head->where('name', 'Assets'))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('name', 'Assets')))
            ->where(fn (Builder $query) => $query->whereNull('code')->orWhereNotIn('code', [...self::CONTROL_CODES, ...BranchAccounts::CONTROL_CODES]))
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', SaleController::PARTY_SUBGROUPS))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * The settlement (cash or bank) account for a salary payment or advance.
     * Never an HR, perpetual-inventory or inter-branch control account: paying "from" one
     * would move that control account without its sub-ledger.
     *
     * @param  array<string, mixed>  $data
     */
    public static function settlementAccountId(array $data): int
    {
        $accountId = match ($data['payment_mode'] ?? null) {
            'cash' => static::id('AS1'),
            'bank' => (int) (($data['bank_account_id'] ?? null) ?: throw new InvalidArgumentException('A bank account is required for a bank payment.')),
            default => throw new InvalidArgumentException('Unknown payment mode: '.($data['payment_mode'] ?? 'none')),
        };

        static::assertNotControlAccounts([$accountId]);
        ManufacturingAccounts::assertNotControlAccounts([$accountId]);
        BranchAccounts::assertNotControlAccounts([$accountId]);

        return $accountId;
    }
}
