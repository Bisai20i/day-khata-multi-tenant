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

    /**
     * Current Liabilities: TDS withheld and not yet paid to the tax office. The
     * same account purchase TDS credits, so it is deliberately NOT a control
     * account (no sub-ledger drift to protect); salary TDS per staff member is
     * read from their payslips (`hr_payroll_lines.tds`).
     */
    public const TDS_PAYABLE = 'LIA21';

    /**
     * Current Liabilities: social security fund contributions accrued and not
     * yet paid to the fund. Payroll credits it with the employee's withholding
     * (taken out of net pay) plus the employer's contribution (EMPLOYER_SSF_EXPENSE),
     * as one liability. Not a control account: per-staff amounts are read from
     * the payslips (`hr_payroll_lines.ssf_employee` / `ssf_employer`).
     */
    public const SSF_PAYABLE = 'LIA23';

    /**
     * Current Liabilities: citizen investment trust contributions accrued and
     * not yet paid to the trust. Payroll credits it with the employee's
     * withholding plus the employer's contribution (EMPLOYER_CIT_EXPENSE), as
     * one liability (`hr_payroll_lines.cit_employee` / `cit_employer`).
     */
    public const CIT_PAYABLE = 'LIA24';

    /**
     * Indirect Expenses: the employer's SSF contribution, an extra cost on top
     * of gross pay. Payroll debits it; the credit goes to SSF_PAYABLE.
     */
    public const EMPLOYER_SSF_EXPENSE = 'EXE32';

    /**
     * Indirect Expenses: the employer's CIT contribution, an extra cost on top
     * of gross pay. Payroll debits it; the credit goes to CIT_PAYABLE.
     */
    public const EMPLOYER_CIT_EXPENSE = 'EXE33';

    /**
     * Direct Expenses (above Gross Profit): wages of hourly / labour / site
     * staff. Payroll debits it through the DIRECT_WAGES starter pay component
     * (or any component a tenant maps to it); monthly salaries stay on
     * SALARIES_AND_WAGES under Indirect Expenses.
     */
    public const DIRECT_WAGES = 'EXE28';

    public const SALARIES_AND_WAGES = 'EXE25';

    public const STAFF_BONUS = 'EXE26';

    public const STAFF_ALLOWANCES = 'EXE27';

    /**
     * @var list<string>
     */
    public const CONTROL_CODES = [self::SALARY_PAYABLE, self::STAFF_ADVANCES_RECEIVABLE];

    /**
     * The pay components every tenant starts with, in display order. Existing
     * tenants got the first three from 2026_11_05_100100 and Direct Wages from
     * 2026_11_21_130000_add_direct_wages_pay_component. `contribution_base`
     * marks the component the SSF / CIT percentages apply to (the basic pay);
     * only Salary is one (existing tenants: 2026_11_21_180100).
     *
     * @var list<array{code: string, name: string, account: string, contribution_base: bool}>
     */
    public const STARTER_COMPONENTS = [
        ['code' => 'SALARY', 'name' => 'Salary', 'account' => self::SALARIES_AND_WAGES, 'contribution_base' => true],
        ['code' => 'BONUS', 'name' => 'Bonus', 'account' => self::STAFF_BONUS, 'contribution_base' => false],
        ['code' => 'ALLOWANCE', 'name' => 'Allowance', 'account' => self::STAFF_ALLOWANCES, 'contribution_base' => false],
        ['code' => 'DIRECT_WAGES', 'name' => 'Direct Wages', 'account' => self::DIRECT_WAGES, 'contribution_base' => false],
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
            ->where(fn (Builder $query) => $query->whereNull('code')->orWhereNotIn('code', [
                ...self::CONTROL_CODES,
                ...BranchAccounts::CONTROL_CODES,
                'AS11', 'AS12', 'AS13', 'AS14', 'ASA23', 'AS31',
            ]))
            ->whereDoesntHave('group', fn (Builder $group) => $group->where('name', 'Fixed Assets'))
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', [
                ...SaleController::PARTY_SUBGROUPS,
                'Stock',
            ]))
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
