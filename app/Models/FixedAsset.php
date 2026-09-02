<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable([
    'asset_code', 'asset_name', 'account_id', 'category', 'purchase_date',
    'cost', 'salvage_value', 'depreciation_method', 'depreciation_rate',
    'accumulated_depreciation', 'status', 'disposal_date', 'disposal_amount',
    'journal_voucher_id', 'disposal_journal_voucher_id', 'created_by',
])]
class FixedAsset extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'depreciation_rate' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'disposal_date' => 'date',
            'disposal_amount' => 'decimal:2',
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

    public function getWdvAttribute(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    /**
     * Auto-creates the asset's own ledger Account, posts a balanced
     * purchase voucher (debit the new asset account, credit the
     * settlement account), and creates the FixedAsset row.
     *
     * @param  array{asset_name: string, category: string, purchase_date: string, cost: float, salvage_value?: float, depreciation_method: string, depreciation_rate: float, payment_mode: string, bank_account_id?: int|null, supplier_id?: int|null, narration?: string|null}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $pool = DepreciationPool::from($data['category']);
            $method = DepreciationMethod::from($data['depreciation_method']);
            $cost = round((float) $data['cost'], 2);
            $salvageValue = round((float) ($data['salvage_value'] ?? 0), 2);
            $rate = round((float) $data['depreciation_rate'], 2);

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

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::FixedAssetPurchase->value,
                    'date' => $data['purchase_date'],
                    'narration' => $data['narration'] ?? "Fixed asset purchase - {$data['asset_name']}",
                ],
                [
                    ['account_id' => $account->id, 'debit' => $cost, 'credit' => 0, 'narration' => 'Asset cost'],
                    ['account_id' => $settlementAccountId, 'debit' => 0, 'credit' => $cost, 'narration' => 'Settlement'],
                ],
                $actor,
            );

            $asset = static::create([
                'asset_code' => 'FA-PENDING-'.uniqid(),
                'asset_name' => $data['asset_name'],
                'account_id' => $account->id,
                'category' => $pool->value,
                'purchase_date' => $data['purchase_date'],
                'cost' => $cost,
                'salvage_value' => $salvageValue,
                'depreciation_method' => $method->value,
                'depreciation_rate' => $rate,
                'accumulated_depreciation' => 0,
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
     * not-fully-depreciated asset that hasn't already been posted for
     * $fiscalYear (the fixed_asset_depreciations unique constraint backs
     * this up too). Uses JournalVoucher::write() directly (bypassing
     * post()'s "current fiscal year" resolution) because the caller always
     * targets a specific fiscal year explicitly - both the manual admin
     * action and the FiscalYear::close() hook below.
     *
     * @return array{posted: int, total: float}
     */
    public static function postDepreciationForFiscalYear(FiscalYear $fiscalYear, User $actor): array
    {
        $depreciationExpense = Account::where('code', 'EXE20')->firstOrFail();
        $accumulatedDepreciationAccount = Account::where('code', 'AS31')->firstOrFail();

        $postedCount = 0;
        $totalPosted = 0.0;

        foreach (static::where('status', 'active')->get() as $asset) {
            $alreadyPosted = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                ->where('fiscal_year_id', $fiscalYear->id)
                ->exists();

            if ($alreadyPosted) {
                continue;
            }

            $depreciableBase = round((float) $asset->cost - (float) $asset->salvage_value, 2);
            $openingWdv = round((float) $asset->cost - (float) $asset->accumulated_depreciation, 2);
            $remainingDepreciable = round($depreciableBase - (float) $asset->accumulated_depreciation, 2);

            if ($remainingDepreciable <= 0) {
                continue;
            }

            $amount = $asset->depreciation_method === DepreciationMethod::StraightLine->value
                ? round($depreciableBase * (float) $asset->depreciation_rate / 100, 2)
                : round($openingWdv * (float) $asset->depreciation_rate / 100, 2);

            $amount = min($amount, $remainingDepreciable);

            if ($amount <= 0) {
                continue;
            }

            $closingWdv = round($openingWdv - $amount, 2);
            $postedDate = $fiscalYear->end_date->toDateString();

            $voucher = JournalVoucher::write(
                $fiscalYear,
                VoucherType::Depreciation,
                $postedDate,
                "Depreciation - {$asset->asset_name} ({$asset->asset_code})",
                null,
                $actor,
                [
                    ['account_id' => $depreciationExpense->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $accumulatedDepreciationAccount->id, 'debit' => 0, 'credit' => $amount],
                ],
            );

            FixedAssetDepreciation::create([
                'fixed_asset_id' => $asset->id,
                'fiscal_year_id' => $fiscalYear->id,
                'journal_voucher_id' => $voucher->id,
                'posted_date' => $postedDate,
                'opening_wdv' => $openingWdv,
                'depreciation_amount' => $amount,
                'closing_wdv' => $closingWdv,
            ]);

            $asset->update(['accumulated_depreciation' => round((float) $asset->accumulated_depreciation + $amount, 2)]);

            $postedCount++;
            $totalPosted = round($totalPosted + $amount, 2);
        }

        return ['posted' => $postedCount, 'total' => $totalPosted];
    }

    /**
     * Disposes this asset: clears its accumulated depreciation, settles
     * any proceeds, removes its cost from the books, and posts the
     * gain/loss on disposal - all as one balanced voucher. diff > 0 is a
     * gain, diff < 0 is a loss; diff == 0 needs neither line.
     */
    public function dispose(User $actor, string $disposalDate, float $proceeds, string $mode, ?int $bankAccountId = null): void
    {
        if ($this->status === 'disposed') {
            throw new InvalidArgumentException('This asset has already been disposed.');
        }

        DB::transaction(function () use ($actor, $disposalDate, $proceeds, $mode, $bankAccountId) {
            $proceeds = round($proceeds, 2);
            $accumulated = round((float) $this->accumulated_depreciation, 2);
            $cost = round((float) $this->cost, 2);
            $diff = round($proceeds + $accumulated - $cost, 2);

            $lines = [];

            if ($accumulated > 0) {
                $accumulatedDepreciationAccount = Account::where('code', 'AS31')->firstOrFail();
                $lines[] = ['account_id' => $accumulatedDepreciationAccount->id, 'debit' => $accumulated, 'credit' => 0, 'narration' => 'Remove accumulated depreciation'];
            }

            if ($proceeds > 0) {
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

                $lines[] = ['account_id' => $settlementAccountId, 'debit' => $proceeds, 'credit' => 0, 'narration' => 'Disposal proceeds'];
            }

            if ($diff < 0) {
                $lossAccount = Account::where('code', 'EXE21')->firstOrFail();
                $lines[] = ['account_id' => $lossAccount->id, 'debit' => -$diff, 'credit' => 0, 'narration' => 'Loss on disposal'];
            }

            $lines[] = ['account_id' => $this->account_id, 'debit' => 0, 'credit' => $cost, 'narration' => 'Remove asset cost'];

            if ($diff > 0) {
                $gainAccount = Account::where('code', 'INI30')->firstOrFail();
                $lines[] = ['account_id' => $gainAccount->id, 'debit' => 0, 'credit' => $diff, 'narration' => 'Gain on disposal'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::AssetDisposal->value,
                    'date' => $disposalDate,
                    'narration' => "Disposal - {$this->asset_name} ({$this->asset_code})",
                ],
                $lines,
                $actor,
            );

            $this->update([
                'status' => 'disposed',
                'disposal_date' => $disposalDate,
                'disposal_amount' => $proceeds,
                'disposal_journal_voucher_id' => $voucher->id,
            ]);
        });
    }
}
