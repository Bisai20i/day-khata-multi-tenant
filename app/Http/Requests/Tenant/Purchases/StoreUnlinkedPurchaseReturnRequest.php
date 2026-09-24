<?php

namespace App\Http\Requests\Tenant\Purchases;

use App\Models\Item;
use App\Models\ItemUnit;
use App\Rules\AccountUnderHead;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * An unlinked purchase return has no bill to bound it, so it is the most
 * abusable document in the purchase flow (audit PUR-03): admin only, a reason
 * is mandatory, the total must be confirmed, and an entered rate may not
 * exceed what the goods cost (the item's weighted average cost as of the
 * return date, scaled to the chosen unit). A supplier-backed return can be
 * credited to the supplier account (payment_mode "credit") instead of forcing
 * a cash or bank refund.
 */
class StoreUnlinkedPurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->role?->slug === 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id', 'required_if:payment_mode,credit'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'payment_mode' => ['required', 'in:cash,bank,partial,credit'],
            'bank_account_id' => ['nullable', 'exists:accounts,id', new AccountUnderHead('Assets')],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'bank_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'reason' => ['required', 'string', 'max:255'],
            'expected_total' => ['required', 'numeric', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', Rule::exists('items', 'id')->where('is_active', true)],
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            // Blank means "value at average cost"; an entered rate is capped
            // in after() below.
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $date = $this->date('date')->toDateString();

            foreach ((array) $this->input('lines', []) as $index => $line) {
                $rate = $line['rate'] ?? null;

                if ($rate === null || $rate === '') {
                    continue;
                }

                try {
                    $item = Item::find($line['item_id']);
                    $factor = BigDecimal::one();

                    if (! empty($line['item_unit_id'])) {
                        $unit = ItemUnit::find($line['item_unit_id']);
                        $factor = $unit && (int) $unit->item_id === (int) $item->id
                            ? BigDecimal::of($unit->conversion_factor)
                            : BigDecimal::one();
                    }

                    $ceiling = (StockCosting::averageCost($item, $date)
                        ?? Quantity::ofNullable($item->purchase_rate)?->toBigDecimal()
                        ?? BigDecimal::zero())->multipliedBy($factor);

                    if (Quantity::of($rate)->toBigDecimal()->isGreaterThan($ceiling)) {
                        $validator->errors()->add(
                            "lines.{$index}.rate",
                            "The rate cannot exceed the cost of {$item->name} (average cost per selected unit is "
                            .$ceiling->toScale(4, RoundingMode::HalfUp).').'
                        );
                    }
                } catch (Throwable) {
                    $validator->errors()->add("lines.{$index}.rate", 'The rate could not be checked.');
                }
            }
        }];
    }
}
