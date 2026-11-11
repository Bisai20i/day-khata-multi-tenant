<?php

namespace App\Support\Manufacturing;

use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucherLine;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use App\Support\Branches\BranchContext;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Proves the perpetual inventory ledgers agree with the general ledger
 * (MANUFACTURING CONTRACTS MC9), class by class:
 *
 * - Raw Material: the `AS12` balance against the sum of every raw material
 *   item's `cost_value` balance (invariant P1, PerpetualCosting::classValue()).
 * - WIP: the `AS13` balance against the net of `production_wip_entries`
 *   (invariant P2, WipLedger::balance()).
 * - Finished Goods: the `AS14` balance against the finished goods items'
 *   `cost_value` balance (P1 again).
 *
 * Every perpetual document posts its journal and its subledger row in one
 * transaction from the same figure, so a non-zero difference always means
 * something wrote one side without the other - a manual journal straight
 * into an inventory account, a hand-edited `cost_value`, a half-applied
 * data fix. FiscalYear::close() and relock() refuse to proceed on one, since
 * the snapshots they then write become next year's verified opening RM/WIP/FG
 * figures and a wrong opening would carry the error forward forever.
 *
 * The GL side reads only vouchers belonging to $fiscalYear (dated on or
 * before $asOf), exactly like FiscalYear::netBalance(): the year's own
 * opening-balance voucher already carries every earlier year in, so the
 * in-year balance is the cumulative balance. The subledger side is
 * cumulative by date. The two therefore agree for any year whose opening
 * balances were carried forward by close().
 *
 * Trading stock is deliberately not here: it is periodic, never posted until
 * the year-end trading pair, so there is nothing to reconcile it against.
 *
 * Per branch (plan 12, invariant I8): checkBranch() runs the same three
 * comparisons for one branch. The GL side is the branch's own voucher lines
 * (journal_voucher_lines.branch_id); the stock side is the cost_value of
 * movements in the branch's stores (a movement has no branch column, its
 * store decides) and the WIP rows of the branch's production entries, or of
 * the material issue note for a row not yet attached to an entry. Every
 * perpetual document files its voucher in its store's branch, so each branch
 * reconciles on its own and the branches add up to check(). check() and
 * assertReconciled() are left exactly as they were and still run first.
 * FiscalYear::close() and relock() then call assertBranchesReconciled() when
 * the company has more than one branch, so a single-branch company closes
 * exactly as before; the reports still read the company-wide figures.
 */
class InventoryReconciliation
{
    public const RAW_MATERIAL = 'raw_material';

    public const WIP = 'wip';

    public const FINISHED_GOOD = 'finished_good';

    /**
     * Per class: the GL balance, the subledger balance and `gl - subledger`.
     * The shape (keys and their order) is relied on by the manufacturing
     * reports (M07), so it is fixed.
     *
     * @return array{
     *     raw_material: array{gl: Money, subledger: Money, difference: Money},
     *     wip: array{gl: Money, subledger: Money, difference: Money},
     *     finished_good: array{gl: Money, subledger: Money, difference: Money},
     * }
     */
    public static function check(FiscalYear $fiscalYear, string $asOf): array
    {
        $codes = [
            self::RAW_MATERIAL => ManufacturingAccounts::RAW_MATERIAL,
            self::WIP => ManufacturingAccounts::WIP,
            self::FINISHED_GOOD => ManufacturingAccounts::FINISHED_GOODS,
        ];

        $glByCode = static::glBalancesByCode($fiscalYear, $asOf, array_values($codes));

        $subledgers = [
            self::RAW_MATERIAL => PerpetualCosting::classValue(InventoryClass::RawMaterial, $asOf),
            self::WIP => WipLedger::balance(null, $asOf),
            self::FINISHED_GOOD => PerpetualCosting::classValue(InventoryClass::FinishedGood, $asOf),
        ];

        $result = [];

        foreach ($codes as $key => $code) {
            $gl = $glByCode[$code];
            $subledger = $subledgers[$key];

            $result[$key] = [
                'gl' => $gl,
                'subledger' => $subledger,
                'difference' => $gl->minus($subledger),
            ];
        }

        return $result;
    }

    /**
     * Runs check() and throws when any class is out, naming every class
     * that differs with both sides and the difference, so the accountant
     * knows where to look without opening a report first. Returns the check
     * result for the caller that goes on to write snapshots.
     *
     * @return array{
     *     raw_material: array{gl: Money, subledger: Money, difference: Money},
     *     wip: array{gl: Money, subledger: Money, difference: Money},
     *     finished_good: array{gl: Money, subledger: Money, difference: Money},
     * }
     *
     * @throws InvalidArgumentException
     */
    public static function assertReconciled(FiscalYear $fiscalYear, string $asOf, string $action = 'closed'): array
    {
        $result = static::check($fiscalYear, $asOf);

        $problems = [];

        foreach ($result as $key => $row) {
            if ($row['difference']->isZero()) {
                continue;
            }

            $problems[] = sprintf(
                '%s: the ledger (%s) shows %s but the stock records show %s, a difference of %s',
                static::label($key),
                static::accountCode($key),
                $row['gl']->toString(),
                $row['subledger']->toString(),
                $row['difference']->toString(),
            );
        }

        if ($problems !== []) {
            throw new InvalidArgumentException(
                "\"{$fiscalYear->name}\" cannot be {$action} because its inventory does not reconcile as at {$asOf}. "
                .implode('; ', $problems)
                .'. Correct the entry that posted one side without the other, then try again.'
            );
        }

        return $result;
    }

    /**
     * check() for one branch (invariant I8). Same keys, same shape. Runs
     * unrestricted: an audit needs the branch's whole ledger, whoever asks.
     *
     * @return array{
     *     raw_material: array{gl: Money, subledger: Money, difference: Money},
     *     wip: array{gl: Money, subledger: Money, difference: Money},
     *     finished_good: array{gl: Money, subledger: Money, difference: Money},
     * }
     */
    public static function checkBranch(FiscalYear $fiscalYear, string $asOf, int $branchId): array
    {
        return BranchContext::unrestricted(function () use ($fiscalYear, $asOf, $branchId): array {
            $codes = [
                self::RAW_MATERIAL => ManufacturingAccounts::RAW_MATERIAL,
                self::WIP => ManufacturingAccounts::WIP,
                self::FINISHED_GOOD => ManufacturingAccounts::FINISHED_GOODS,
            ];

            $glByCode = static::glBalancesByCode($fiscalYear, $asOf, array_values($codes), $branchId);

            $subledgers = [
                self::RAW_MATERIAL => static::branchClassValue(InventoryClass::RawMaterial, $asOf, $branchId),
                self::WIP => static::branchWipBalance($asOf, $branchId),
                self::FINISHED_GOOD => static::branchClassValue(InventoryClass::FinishedGood, $asOf, $branchId),
            ];

            $result = [];

            foreach ($codes as $key => $code) {
                $result[$key] = [
                    'gl' => $glByCode[$code],
                    'subledger' => $subledgers[$key],
                    'difference' => $glByCode[$code]->minus($subledgers[$key]),
                ];
            }

            return $result;
        });
    }

    /**
     * checkBranch() for every branch, Main first, then by id. Inactive
     * branches are included: they can still hold stock and balances.
     *
     * @return array<int, array{
     *     raw_material: array{gl: Money, subledger: Money, difference: Money},
     *     wip: array{gl: Money, subledger: Money, difference: Money},
     *     finished_good: array{gl: Money, subledger: Money, difference: Money},
     * }> keyed by branch id
     */
    public static function checkByBranch(FiscalYear $fiscalYear, string $asOf): array
    {
        $branchIds = BranchContext::unrestricted(fn (): array => Branch::query()
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        $result = [];

        foreach ($branchIds as $branchId) {
            $result[$branchId] = static::checkBranch($fiscalYear, $asOf, $branchId);
        }

        return $result;
    }

    /**
     * Throws when any class of any branch is out (I8), naming the branch, the
     * class, both sides and the difference, like assertReconciled(). Called by
     * FiscalYear::close() and relock() when the company has more than one
     * branch; every perpetual document files its voucher in its store's
     * branch, so a mismatch means an entry posted one side into another
     * branch.
     *
     * @return array<int, array<string, array{gl: Money, subledger: Money, difference: Money}>> checkByBranch() output
     *
     * @throws InvalidArgumentException
     */
    public static function assertBranchesReconciled(FiscalYear $fiscalYear, string $asOf, string $action = 'closed'): array
    {
        $result = static::checkByBranch($fiscalYear, $asOf);
        $names = BranchContext::unrestricted(fn (): array => Branch::query()->pluck('name', 'id')->all());

        $problems = [];

        foreach ($result as $branchId => $classes) {
            foreach ($classes as $key => $row) {
                if ($row['difference']->isZero()) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s, %s: the ledger (%s) shows %s but the stock records show %s, a difference of %s',
                    $names[$branchId] ?? "#{$branchId}",
                    static::label($key),
                    static::accountCode($key),
                    $row['gl']->toString(),
                    $row['subledger']->toString(),
                    $row['difference']->toString(),
                );
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException(
                "\"{$fiscalYear->name}\" cannot be {$action} because its inventory does not reconcile branch by branch as at {$asOf}. "
                .implode('; ', $problems)
                .'. Correct the entry that posted one side without the other, or into another branch, then try again.'
            );
        }

        return $result;
    }

    /**
     * Human label for a reconciliation key.
     */
    public static function label(string $key): string
    {
        return match ($key) {
            self::RAW_MATERIAL => InventoryClass::RawMaterial->label(),
            self::WIP => 'Work in Process',
            self::FINISHED_GOOD => InventoryClass::FinishedGood->label(),
            default => throw new InvalidArgumentException("Unknown inventory reconciliation class [{$key}]."),
        };
    }

    private static function accountCode(string $key): string
    {
        return match ($key) {
            self::RAW_MATERIAL => ManufacturingAccounts::RAW_MATERIAL,
            self::WIP => ManufacturingAccounts::WIP,
            self::FINISHED_GOOD => ManufacturingAccounts::FINISHED_GOODS,
            default => throw new InvalidArgumentException("Unknown inventory reconciliation class [{$key}]."),
        };
    }

    /**
     * Net (debit - credit) per account code inside one fiscal year up to
     * $asOf, summed as scaled integers for the same reason
     * FiscalYear::netBalance() does it (SQLite REAL affinity). whereDate()
     * because a date-cast column is stored as "Y-m-d H:i:s" on SQLite.
     *
     * An account missing from the chart reads as zero rather than throwing
     * (unlike ManufacturingAccounts::id()): a tenant that has never used
     * manufacturing must still be able to close its year, and if stock
     * records exist without the account the difference below still blocks
     * the close, which is the right outcome.
     *
     * $branchId narrows it to that branch's lines (checkBranch()); null is
     * every line, the query check() has always run.
     *
     * @param  list<string>  $codes
     * @return array<string, Money>
     */
    private static function glBalancesByCode(FiscalYear $fiscalYear, string $asOf, array $codes, ?int $branchId = null): array
    {
        $accountIdsByCode = Account::query()->whereIn('code', $codes)->pluck('id', 'code')->all();
        $balances = array_fill_keys($codes, Money::zero());

        if ($accountIdsByCode === []) {
            return $balances;
        }

        $cast = (new JournalVoucherLine)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereIn('account_id', array_values($accountIdsByCode))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereHas('journalVoucher', fn (Builder $query) => $query
                ->where('fiscal_year_id', $fiscalYear->id)
                ->whereDate('date', '<=', $asOf))
            ->selectRaw(
                "account_id, COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('account_id')
            ->pluck('net_scaled', 'account_id')
            ->all();

        foreach ($accountIdsByCode as $code => $accountId) {
            $balances[$code] = Money::of(
                BigDecimal::of((int) ($rows[$accountId] ?? 0))->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $balances;
    }

    /**
     * Net cost_value of one perpetual class held in one branch's stores up
     * to $asOf: PerpetualCosting::classValue() narrowed by store, with the
     * same signs (stock-increasing types add, every other type subtracts) and
     * the same scaled-integer sum.
     */
    private static function branchClassValue(InventoryClass $class, string $asOf, int $branchId): Money
    {
        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaledValue = "CAST(ROUND(cost_value * 100) AS {$cast})";

        $inTypes = array_values(array_map(
            fn (StockMovementType $type): string => $type->value,
            array_filter(StockMovementType::cases(), fn (StockMovementType $type): bool => $type->direction() === 1),
        ));
        $placeholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $scaled = ItemStockMovement::query()
            ->where('cancelled', false)
            ->whereNotNull('cost_value')
            ->whereDate('date', '<=', $asOf)
            ->whereIn('store_id', Store::query()->select('id')->where('branch_id', $branchId))
            ->whereIn('item_id', Item::query()->select('id')->where('inventory_class', $class->value))
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN movement_type IN ({$placeholders}) THEN {$scaledValue} ELSE -{$scaledValue} END), 0) as net",
                $inTypes,
            )
            ->value('net');

        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * Net WIP value of one branch up to $asOf: WipLedger::balance() narrowed
     * to the rows of the branch's production entries or, for a row of a note
     * not attached to any entry, the note's branch. A row with neither (none
     * is ever written) counts in Main, so the branches always add up to
     * WipLedger::balance().
     */
    private static function branchWipBalance(string $asOf, int $branchId): Money
    {
        $cast = (new ProductionWipEntry)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $scaled = ProductionWipEntry::query()
            ->leftJoin('production_entries as wip_entry', 'wip_entry.id', '=', 'production_wip_entries.production_entry_id')
            ->leftJoin('material_issue_notes as wip_note', 'wip_note.id', '=', 'production_wip_entries.material_issue_note_id')
            ->where('production_wip_entries.cancelled', false)
            ->whereDate('production_wip_entries.date', '<=', $asOf)
            ->whereRaw('COALESCE(wip_entry.branch_id, wip_note.branch_id, ?) = ?', [BranchContext::mainBranchId(), $branchId])
            ->selectRaw("COALESCE(SUM(production_wip_entries.direction * CAST(ROUND(production_wip_entries.value * 100) AS {$cast})), 0) as net")
            ->value('net');

        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }
}
