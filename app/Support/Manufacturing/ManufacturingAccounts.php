<?php

namespace App\Support\Manufacturing;

use App\Models\Account;
use InvalidArgumentException;
use RuntimeException;

/**
 * Codes of the six system accounts perpetual inventory posts to (MC2), and a
 * request-cached code -> id lookup so posting code never re-queries them.
 */
class ManufacturingAccounts
{
    public const RAW_MATERIAL = 'AS12';

    public const WIP = 'AS13';

    public const FINISHED_GOODS = 'AS14';

    public const COGS = 'EXE10';

    public const ADJUSTMENT = 'EXE23';

    public const PRODUCTION_LOSS = 'EXE24';

    /**
     * Perpetual inventory control accounts. Only stock documents (purchases,
     * sales, material issues, production, adjustments) may move them, because
     * each posting there writes the matching stock-ledger value at the same
     * time. A hand-posted journal into one of them would split the GL from the
     * stock ledger, and the year-end close refuses to run until it is reversed
     * (MC9), so manual entry points reject them up front instead.
     *
     * @var list<string>
     */
    public const CONTROL_CODES = [self::RAW_MATERIAL, self::WIP, self::FINISHED_GOODS];

    /**
     * Throws when any of the given account ids is an inventory control
     * account. For manual vouchers and the opening balance import.
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
                '"'.$control->implode('", "').'" can only be changed by stock documents (purchases, sales, material issues, '
                .'production entries, stock adjustments), so its balance always matches the stock ledger. Use one of those instead.'
            );
        }
    }

    /**
     * Resolves a system account id by code. Deliberately uncached: tenants
     * (and test tenants recreated under the same id) can rebuild their chart
     * of accounts, and a stale id would post to the wrong account. The
     * lookup is one indexed query.
     *
     * @throws RuntimeException when the account does not exist yet
     */
    public static function id(string $code): int
    {
        $id = Account::where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("System account {$code} is missing. Run the tenant migrations to add the manufacturing accounts.");
        }

        return (int) $id;
    }
}
