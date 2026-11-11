<?php

namespace App\Support\Branches;

use App\Enums\InventoryClass;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * The journal lines of the two BranchStockTransfer vouchers of a transfer
 * between branches (plan 12, BR18 and BR25), kept out of StockTransfer so the
 * model only decides when a voucher is posted, not what it says.
 *
 * Every amount comes from the stored stock_transfer_lines: line_value
 * grouped by the line's stored inventory_class. Nothing is re-costed at
 * receipt, so both vouchers carry the same figures and Inter-Branch
 * Clearing (ASA25) nets to zero company-wide once a transfer is received
 * (I3). With P the trading lines, R the raw material and F the finished
 * goods, T = P + R + F = total_value:
 *
 * - Dispatch, in the sender's branch: Dr ASA25 T (counterparty: receiver),
 *   Cr INI23 P, Cr AS12 R, Cr AS14 F.
 * - Receipt, in the receiver's branch: Dr EXE11 P, Dr AS12 R, Dr AS14 F,
 *   Cr ASA25 T (counterparty: sender).
 *
 * Zero amounts are left out, so a transfer of trading items only produces
 * exactly the two-line vouchers BR18 posted (same keys, same order, same
 * figures), and AS12 / AS14 are not even looked up. INI23 / EXE11 are the
 * periodic trading-account legs and never carry perpetual value: raw
 * material and finished goods are on the balance sheet in real time, and
 * their per-branch AS12 / AS14 balance must follow the goods for invariant
 * P1 to hold in each branch (the TransferOut / TransferIn movements carry the
 * same cost_value as these lines).
 *
 * Each voucher's lines are all in its own branch, so each balances inside
 * one branch and no clearing line is auto-added.
 */
final class TransferLedgerLines
{
    /**
     * Lines of the dispatch voucher, in the sending branch.
     *
     * @return list<array<string, int|string>>
     *
     * @throws InvalidArgumentException When the stored lines do not add up to the header totals.
     */
    public static function dispatch(StockTransfer $transfer, int $clearingId, int $transferredId): array
    {
        $totals = self::classTotals($transfer);
        $branchId = (int) $transfer->from_branch_id;

        $lines = [[
            'account_id' => $clearingId,
            'debit' => Money::of($transfer->total_value)->toString(),
            'branch_id' => $branchId,
            'counterparty_branch_id' => (int) $transfer->to_branch_id,
        ]];

        foreach (self::classLegs($totals, $transferredId) as [$accountId, $amount]) {
            $lines[] = ['account_id' => $accountId, 'credit' => $amount->toString(), 'branch_id' => $branchId];
        }

        return $lines;
    }

    /**
     * Lines of the receipt voucher, in the receiving branch.
     *
     * @return list<array<string, int|string>>
     *
     * @throws InvalidArgumentException When the stored lines do not add up to the header totals.
     */
    public static function receipt(StockTransfer $transfer, int $clearingId, int $receivedId): array
    {
        $totals = self::classTotals($transfer);
        $branchId = (int) $transfer->to_branch_id;

        $lines = [];

        foreach (self::classLegs($totals, $receivedId) as [$accountId, $amount]) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'branch_id' => $branchId];
        }

        $lines[] = [
            'account_id' => $clearingId,
            'credit' => Money::of($transfer->total_value)->toString(),
            'branch_id' => $branchId,
            'counterparty_branch_id' => (int) $transfer->from_branch_id,
        ];

        return $lines;
    }

    /**
     * Stored line values per class, checked against the header: all of them
     * must add up to total_value and the perpetual ones to perpetual_value,
     * or the transfer's paper, its vouchers and its stock movements could
     * disagree, so nothing is posted.
     *
     * @return array<string, Money> keyed by InventoryClass value, every class present
     *
     * @throws InvalidArgumentException
     */
    public static function classTotals(StockTransfer $transfer): array
    {
        $totals = [];

        foreach (InventoryClass::cases() as $class) {
            $totals[$class->value] = Money::zero();
        }

        /** @var StockTransferLine $line */
        foreach ($transfer->lines()->get(['id', 'inventory_class', 'line_value']) as $line) {
            $class = $line->inventory_class ?? InventoryClass::Trading;
            $totals[$class->value] = $totals[$class->value]->plus(Money::of($line->line_value ?? '0'));
        }

        $perpetual = Money::zero();

        foreach (InventoryClass::cases() as $class) {
            if ($class->isPerpetual()) {
                $perpetual = $perpetual->plus($totals[$class->value]);
            }
        }

        if (! Money::sum($totals)->isEqualTo(Money::of($transfer->total_value ?? '0'))
            || ! $perpetual->isEqualTo(Money::of($transfer->perpetual_value ?? '0'))) {
            throw new InvalidArgumentException(
                "Stock transfer #{$transfer->id} cannot be posted: its line values do not add up to its total. Contact support."
            );
        }

        return $totals;
    }

    /**
     * The per-class legs, trading first then the perpetual classes in enum
     * order (raw material, finished goods), zero amounts dropped.
     *
     * @param  array<string, Money>  $totals
     * @return list<array{0: int, 1: Money}>
     */
    private static function classLegs(array $totals, int $tradingAccountId): array
    {
        $legs = [];

        foreach (InventoryClass::cases() as $class) {
            $amount = $totals[$class->value];

            if (! $amount->isPositive()) {
                continue;
            }

            $code = $class->inventoryAccountCode();
            try {
                $accountId = $code === null ? $tradingAccountId : ManufacturingAccounts::id($code);
            } catch (RuntimeException $exception) {
                // A missing AS12/AS14 would otherwise surface as a 500; the
                // transfer controllers map InvalidArgumentException to a message.
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            $legs[] = [$accountId, $amount];
        }

        return $legs;
    }
}
