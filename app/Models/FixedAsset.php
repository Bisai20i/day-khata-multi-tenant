<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A fixed asset posts through the same JournalVoucher::post()/write()
 * engine every other module uses - never raw ledger inserts. Each asset
 * gets its OWN ledger Account under the "Fixed Assets" AccountGroup (no
 * subgroup - that group has none), not a shared control account, which is
 * what lets each asset appear as its own balance-sheet line. Depreciation
 * is posted once per fiscal year per asset, either manually (admin action)
 * or automatically from FiscalYear::close() before the P&L sweep, since
 * depreciation must reduce the year's profit before it is transferred to
 * Profit & Loss. See day-khata-multi-tenant mem.md for the legacy research
 * this was built from.
 *
 * ## The depreciation rule this implements
 *
 * Written-down value at the asset's own pool rate (DepreciationPool A to E
 * pre-fill the statutory rates; the rate stays editable per asset), charged
 * on the opening WDV for `wdv` assets and on the depreciable base for `slm`
 * assets, **prorated by the days the asset was actually held inside the
 * fiscal year**. An asset bought two months before the year ends is charged
 * two months of depreciation, not a full year (audit P1, no proration by
 * acquisition date), and a disposed asset is charged up to its disposal date
 * and no further.
 *
 * Nepal's Income Tax Act schedule 2 states the same idea as a coarser
 * "absorption" rule - an addition in the year's first third absorbs the full
 * rate, the second third two thirds, the last third one third. Days held is
 * the finer-grained form of exactly that intent, it can never charge MORE
 * than the statutory rule would, and it is what the task set for this module
 * specifies. A tenant that must file on the statutory trimesters can still
 * reconcile, because the charge is recorded per asset per year on
 * fixed_asset_depreciations.
 *
 * Depreciation never takes an asset below its salvage value, and every
 * amount is exact Money: the old code multiplied floats and called round(),
 * which on PHP 8.4 charged a paisa too little on a 15% run against
 * 43,14,071.10 (audit P0-1).
 */
#[Fillable([
    'asset_code', 'asset_name', 'account_id', 'category', 'purchase_date',
    'cost', 'vat_amount', 'salvage_value', 'depreciation_method', 'depreciation_rate',
    'accumulated_depreciation', 'status', 'disposal_date', 'disposal_amount',
    'journal_voucher_id', 'disposal_journal_voucher_id', 'created_by',
])]
class FixedAsset extends Model
{
    /**
     * Written-down value, appended to every serialized asset as an exact
     * 2-decimal string so the Index page never has to subtract two numbers
     * in JavaScript.
     *
     * @var list<string>
     */
    protected $appends = ['wdv'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'cost' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'salvage_value' => Decimal::class.':2',
            'depreciation_rate' => Decimal::class.':2',
            'accumulated_depreciation' => Decimal::class.':2',
            'disposal_date' => 'date',
            'disposal_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function disposalJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'disposal_journal_voucher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<FixedAssetDepreciation, $this>
     */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /**
     * Cost less accumulated depreciation. Exact Money, never a float
     * subtraction.
     */
    protected function wdv(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Money::of($this->cost)->minus(Money::of($this->accumulated_depreciation))->toString(),
        );
    }

    /**
     * Auto-creates the asset's own ledger Account, posts a balanced
     * purchase voucher (debit the new asset account, credit the
     * settlement account), and creates the FixedAsset row.
     *
     * Input VAT (T14, CONTRACTS/accounting parity, audit section 3): a
     * fixed asset bought from a VAT-registered supplier carries recoverable
     * VAT the same way a stock purchase does. `vat_rate` (0 to 100, default
     * 0 - most fixed-asset bills off a small vendor carry none) adds a
     * `Dr ASA23 (Vat Receivable)` line for `cost.percent(vat_rate)`, on top
     * of the settlement, and the same convention Purchase::post() already
     * uses. The asset's own cost line stays net of VAT - VAT is recoverable,
     * not part of what gets depreciated.
     *
     * @param  array{asset_name: string, category: string, purchase_date: string, cost: string|float, vat_rate?: string|float|null, salvage_value?: string|float|null, depreciation_method: string, depreciation_rate: string|float, payment_mode: string, bank_account_id?: int|null, supplier_id?: int|null, narration?: string|null}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $pool = DepreciationPool::from($data['category']);
            $method = DepreciationMethod::from($data['depreciation_method']);
            $cost = Money::of($data['cost']);
            $salvageValue = Money::ofNullable($data['salvage_value'] ?? null) ?? Money::zero();
            $rate = Money::of($data['depreciation_rate']);
            $vatAmount = $cost->percent($data['vat_rate'] ?? '0');

            if (! $cost->isPositive()) {
                throw new InvalidArgumentException('An asset must cost more than zero.');
            }

            if ($salvageValue->isGreaterThan($cost)) {
                throw new InvalidArgumentException('The salvage value cannot be more than the asset cost.');
            }

            // Resolves the year the purchase date actually belongs to, and
            // refuses a date that falls in no year or in a closed one -
            // rather than letting JournalVoucher::write() reject it later
            // with a message about the currently open year (audit P0-11).
            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($data['purchase_date'], $actor);

            $fixedAssetsGroup = AccountGroup::where('name', 'Fixed Assets')->firstOrFail();
            $account = $fixedAssetsGroup->accounts()->create(['name' => $data['asset_name']]);

            $paymentMode = $data['payment_mode'];

            if ($paymentMode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }
                $settlementAccountId = $data['bank_account_id'];
            } elseif ($paymentMode === 'credit') {
                if (empty($data['supplier_id'])) {
                    throw new InvalidArgumentException('A supplier is required for a credit purchase.');
                }
                $settlementAccountId = Supplier::findOrFail($data['supplier_id'])->account_id;
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $settlementTotal = $cost->plus($vatAmount);

            $voucherLines = [
                ['account_id' => $account->id, 'debit' => $cost->toString(), 'credit' => '0.00', 'narration' => 'Asset cost'],
            ];

            if ($vatAmount->isPositive()) {
                $voucherLines[] = ['account_id' => Account::where('code', 'ASA23')->firstOrFail()->id, 'debit' => $vatAmount->toString(), 'credit' => '0.00', 'narration' => 'Input VAT'];
            }

            $voucherLines[] = ['account_id' => $settlementAccountId, 'debit' => '0.00', 'credit' => $settlementTotal->toString(), 'narration' => 'Settlement'];

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::FixedAssetPurchase->value,
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $data['purchase_date'],
                    'narration' => $data['narration'] ?? "Fixed asset purchase - {$data['asset_name']}",
                ],
                $voucherLines,
                $actor,
            );

            $asset = static::create([
                'asset_code' => 'FA-PENDING-'.uniqid(),
                'asset_name' => $data['asset_name'],
                'account_id' => $account->id,
                'category' => $pool->value,
                'purchase_date' => $data['purchase_date'],
                'cost' => $cost->toString(),
                'vat_amount' => $vatAmount->toString(),
                'salvage_value' => $salvageValue->toString(),
                'depreciation_method' => $method->value,
                'depreciation_rate' => $rate->toString(),
                'accumulated_depreciation' => '0.00',
                'status' => 'active',
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);

            $asset->update(['asset_code' => 'FA-'.str_pad((string) $asset->id, 5, '0', STR_PAD_LEFT)]);

            return $asset;
        });
    }

    /**
     * Registers an asset the business already owned before this system went
     * live - an opening cost and, usually, some accumulated depreciation
     * already run up outside these books - with no cash or bank movement
     * (T14, audit section 3: "register an existing asset ... without a
     * payment"). Posts `Dr Asset cost / Cr Accumulated Depreciation (AS31,
     * if any) / Cr Profit & Loss (CA2)` for the net book value: CA2 is this
     * chart's one retained-earnings/opening-balance-equity account (see
     * ChartOfAccountsSeeder), the same account FiscalYear::close() sweeps
     * the year's net profit into, so an opening asset's net book value
     * enters equity exactly the way a manual opening-balance import would if
     * this asset had simply been on the books from day one.
     *
     * Zero accumulated depreciation (a nearly-new asset) or zero net book
     * value (a fully depreciated one) each omit their own line rather than
     * post a zero line, which JournalVoucher::validateLines() rejects; cost
     * being required positive guarantees at least one of the two remaining
     * lines is positive.
     *
     * @param  array{asset_name: string, category: string, purchase_date: string, cost: string|float, accumulated_depreciation?: string|float|null, salvage_value?: string|float|null, depreciation_method: string, depreciation_rate: string|float, narration?: string|null}  $data
     */
    public static function registerExisting(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $pool = DepreciationPool::from($data['category']);
            $method = DepreciationMethod::from($data['depreciation_method']);
            $cost = Money::of($data['cost']);
            $salvageValue = Money::ofNullable($data['salvage_value'] ?? null) ?? Money::zero();
            $rate = Money::of($data['depreciation_rate']);
            $accumulated = Money::ofNullable($data['accumulated_depreciation'] ?? null) ?? Money::zero();

            if (! $cost->isPositive()) {
                throw new InvalidArgumentException('An asset must cost more than zero.');
            }

            if ($salvageValue->isGreaterThan($cost)) {
                throw new InvalidArgumentException('The salvage value cannot be more than the asset cost.');
            }

            if ($accumulated->isGreaterThan($cost)) {
                throw new InvalidArgumentException('Accumulated depreciation cannot be more than the asset cost.');
            }

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($data['purchase_date'], $actor);

            $fixedAssetsGroup = AccountGroup::where('name', 'Fixed Assets')->firstOrFail();
            $account = $fixedAssetsGroup->accounts()->create(['name' => $data['asset_name']]);

            $netBookValue = $cost->minus($accumulated);
            $voucherLines = [
                ['account_id' => $account->id, 'debit' => $cost->toString(), 'credit' => '0.00', 'narration' => 'Asset cost (opening)'],
            ];

            if ($accumulated->isPositive()) {
                $voucherLines[] = ['account_id' => Account::where('code', 'AS31')->firstOrFail()->id, 'debit' => '0.00', 'credit' => $accumulated->toString(), 'narration' => 'Accumulated depreciation (opening)'];
            }

            if ($netBookValue->isPositive()) {
                $voucherLines[] = ['account_id' => Account::where('code', 'CA2')->firstOrFail()->id, 'debit' => '0.00', 'credit' => $netBookValue->toString(), 'narration' => 'Opening balance equity'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::FixedAssetPurchase->value,
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $data['purchase_date'],
                    'narration' => $data['narration'] ?? "Registered existing asset - {$data['asset_name']}",
                ],
                $voucherLines,
                $actor,
            );

            $asset = static::create([
                'asset_code' => 'FA-PENDING-'.uniqid(),
                'asset_name' => $data['asset_name'],
                'account_id' => $account->id,
                'category' => $pool->value,
                'purchase_date' => $data['purchase_date'],
                'cost' => $cost->toString(),
                'vat_amount' => '0.00',
                'salvage_value' => $salvageValue->toString(),
                'depreciation_method' => $method->value,
                'depreciation_rate' => $rate->toString(),
                'accumulated_depreciation' => $accumulated->toString(),
                'status' => 'active',
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);

            $asset->update(['asset_code' => 'FA-'.str_pad((string) $asset->id, 5, '0', STR_PAD_LEFT)]);

            return $asset;
        });
    }

    /**
     * Posts this fiscal year's depreciation for every active,
     * not-fully-depreciated asset that has not already been posted for
     * $fiscalYear.
     *
     * The whole run is one transaction and each asset row is re-read under
     * lockForUpdate() before its charge is computed, so a double click can
     * no longer commit a depreciation voucher and then fail on the
     * fixed_asset_depreciations unique index, leaving an orphan charge in
     * the ledger with no row explaining it (audit P1, manual depreciation
     * run is not atomic). SQLite makes lockForUpdate() a no-op, so the
     * concurrency half is verified by review; the "already posted" re-check
     * inside the lock is what a test can see.
     *
     * @return array{posted: int, total: string}
     */
    public static function postDepreciationForFiscalYear(FiscalYear $fiscalYear, User $actor): array
    {
        return DB::transaction(function () use ($fiscalYear, $actor) {
            $assetIds = static::query()->where('status', 'active')->orderBy('id')->pluck('id');

            $postedCount = 0;
            $totalPosted = Money::zero();

            foreach ($assetIds as $assetId) {
                $asset = static::query()->whereKey($assetId)->lockForUpdate()->first();

                if (! $asset || $asset->status !== 'active') {
                    continue;
                }

                $amount = static::postDepreciationForAsset($asset, $fiscalYear, $actor);

                if ($amount === null) {
                    continue;
                }

                $postedCount++;
                $totalPosted = $totalPosted->plus($amount);
            }

            return ['posted' => $postedCount, 'total' => $totalPosted->toString()];
        });
    }

    /**
     * Posts one asset's charge for one fiscal year, or returns null when
     * nothing is due (already posted, fully depreciated down to salvage, or
     * not held for a single day inside the year).
     *
     * $throughDate stops the proration early - the disposal date, when this
     * runs from dispose(). It is clamped to the fiscal year, so the voucher
     * date always sits inside the year JournalVoucher::write() is given.
     *
     * The caller is responsible for holding the row lock and the
     * transaction.
     */
    private static function postDepreciationForAsset(self $asset, FiscalYear $fiscalYear, User $actor, ?string $throughDate = null): ?Money
    {
        $alreadyPosted = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->exists();

        if ($alreadyPosted) {
            return null;
        }

        $yearStart = CarbonImmutable::parse($fiscalYear->start_date->toDateString());
        $yearEnd = CarbonImmutable::parse($fiscalYear->end_date->toDateString());
        $purchasedOn = CarbonImmutable::parse($asset->purchase_date->toDateString());

        $through = $throughDate === null
            ? $yearEnd
            : CarbonImmutable::parse($throughDate)->startOfDay();

        if ($through->greaterThan($yearEnd)) {
            $through = $yearEnd;
        }

        $heldFrom = $purchasedOn->greaterThan($yearStart) ? $purchasedOn : $yearStart;

        if ($heldFrom->greaterThan($through)) {
            return null;
        }

        // Both ends inclusive: an asset bought on the year's last day is
        // held for one day, not zero.
        $daysHeld = (int) $heldFrom->diffInDays($through) + 1;
        $daysInYear = (int) $yearStart->diffInDays($yearEnd) + 1;

        $cost = Money::of($asset->cost);
        $accumulated = Money::of($asset->accumulated_depreciation);
        $depreciableBase = $cost->minus(Money::of($asset->salvage_value));
        $openingWdv = $cost->minus($accumulated);
        $remainingDepreciable = $depreciableBase->minus($accumulated);

        if (! $remainingDepreciable->isPositive()) {
            return null;
        }

        $fullYearCharge = $asset->depreciation_method === DepreciationMethod::StraightLine->value
            ? $depreciableBase->percent($asset->depreciation_rate)
            : $openingWdv->percent($asset->depreciation_rate);

        $amount = $daysHeld === $daysInYear
            ? $fullYearCharge
            : $fullYearCharge->multipliedByFraction($daysHeld, $daysInYear);

        // Never below salvage: the depreciable base is cost minus salvage,
        // so capping at what is left of it is the same rule.
        $amount = Money::min($amount, $remainingDepreciable);

        if (! $amount->isPositive()) {
            return null;
        }

        $depreciationExpense = Account::where('code', 'EXE20')->firstOrFail();
        $accumulatedDepreciationAccount = Account::where('code', 'AS31')->firstOrFail();

        $postedDate = $through->toDateString();

        $voucher = JournalVoucher::write(
            $fiscalYear,
            VoucherType::Depreciation,
            $postedDate,
            "Depreciation - {$asset->asset_name} ({$asset->asset_code})",
            null,
            $actor,
            [
                ['account_id' => $depreciationExpense->id, 'debit' => $amount->toString(), 'credit' => '0.00'],
                ['account_id' => $accumulatedDepreciationAccount->id, 'debit' => '0.00', 'credit' => $amount->toString()],
            ],
        );

        FixedAssetDepreciation::create([
            'fixed_asset_id' => $asset->id,
            'fiscal_year_id' => $fiscalYear->id,
            'journal_voucher_id' => $voucher->id,
            'posted_date' => $postedDate,
            'opening_wdv' => $openingWdv->toString(),
            'depreciation_amount' => $amount->toString(),
            'closing_wdv' => $openingWdv->minus($amount)->toString(),
        ]);

        $asset->update(['accumulated_depreciation' => $accumulated->plus($amount)->toString()]);

        return $amount;
    }

    /**
     * Disposes this asset: charges the depreciation it earned up to the
     * disposal date, clears its accumulated depreciation, settles any
     * proceeds, removes its cost from the books, and posts the gain/loss on
     * disposal - all inside one transaction, with the asset row locked and
     * its status re-checked inside that lock so a double click cannot post
     * two disposal vouchers for one asset (audit P1).
     *
     * diff > 0 is a gain, diff < 0 is a loss; diff == 0 needs neither line.
     */
    public function dispose(User $actor, string $disposalDate, Money|string|int|null $proceeds, string $mode, ?int $bankAccountId = null): void
    {
        DB::transaction(function () use ($actor, $disposalDate, $proceeds, $mode, $bankAccountId) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'disposed') {
                throw new InvalidArgumentException('This asset has already been disposed.');
            }

            $proceeds = Money::ofNullable($proceeds) ?? Money::zero();

            if ($proceeds->isNegative()) {
                throw new InvalidArgumentException('Disposal proceeds cannot be negative.');
            }

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($disposalDate, $actor);

            if (CarbonImmutable::parse($disposalDate)->startOfDay()->lessThan(CarbonImmutable::parse($locked->purchase_date->toDateString()))) {
                throw new InvalidArgumentException('An asset cannot be disposed of before it was bought.');
            }

            // The part-year charge the asset earned before it left, so the
            // gain or loss is measured against a current WDV rather than
            // against last year's.
            static::postDepreciationForAsset($locked, $fiscalYear, $actor, $disposalDate);
            $locked->refresh();

            $accumulated = Money::of($locked->accumulated_depreciation);
            $cost = Money::of($locked->cost);
            $diff = $proceeds->plus($accumulated)->minus($cost);

            $lines = [];

            if ($accumulated->isPositive()) {
                $accumulatedDepreciationAccount = Account::where('code', 'AS31')->firstOrFail();
                $lines[] = ['account_id' => $accumulatedDepreciationAccount->id, 'debit' => $accumulated->toString(), 'credit' => '0.00', 'narration' => 'Remove accumulated depreciation'];
            }

            if ($proceeds->isPositive()) {
                if ($mode === 'cash') {
                    $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
                } elseif ($mode === 'bank') {
                    if (! $bankAccountId) {
                        throw new InvalidArgumentException('A bank account is required for a bank disposal settlement.');
                    }
                    $settlementAccountId = $bankAccountId;
                } else {
                    throw new InvalidArgumentException("Unknown disposal mode: {$mode}");
                }

                $lines[] = ['account_id' => $settlementAccountId, 'debit' => $proceeds->toString(), 'credit' => '0.00', 'narration' => 'Disposal proceeds'];
            }

            if ($diff->isNegative()) {
                $lossAccount = Account::where('code', 'EXE21')->firstOrFail();
                $lines[] = ['account_id' => $lossAccount->id, 'debit' => $diff->negated()->toString(), 'credit' => '0.00', 'narration' => 'Loss on disposal'];
            }

            $lines[] = ['account_id' => $locked->account_id, 'debit' => '0.00', 'credit' => $cost->toString(), 'narration' => 'Remove asset cost'];

            if ($diff->isPositive()) {
                $gainAccount = Account::where('code', 'INI30')->firstOrFail();
                $lines[] = ['account_id' => $gainAccount->id, 'debit' => '0.00', 'credit' => $diff->toString(), 'narration' => 'Gain on disposal'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::AssetDisposal->value,
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $disposalDate,
                    'narration' => "Disposal - {$locked->asset_name} ({$locked->asset_code})",
                ],
                $lines,
                $actor,
            );

            $locked->update([
                'status' => 'disposed',
                'disposal_date' => $disposalDate,
                'disposal_amount' => $proceeds->toString(),
                'disposal_journal_voucher_id' => $voucher->id,
            ]);

            $this->forceFill($locked->getAttributes())->syncOriginal();
        });
    }
}
