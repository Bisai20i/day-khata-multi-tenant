<?php

namespace App\Support\Manufacturing;

use App\Enums\InventoryClass;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
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
     * @param  list<string>  $codes
     * @return array<string, Money>
     */
    private static function glBalancesByCode(FiscalYear $fiscalYear, string $asOf, array $codes): array
    {
        $accountIdsByCode = Account::query()->whereIn('code', $codes)->pluck('id', 'code')->all();
        $balances = array_fill_keys($codes, Money::zero());

        if ($accountIdsByCode === []) {
            return $balances;
        }

        $cast = (new JournalVoucherLine)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereIn('account_id', array_values($accountIdsByCode))
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
}
