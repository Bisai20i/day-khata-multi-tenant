<?php

namespace App\Http\Requests\Tenant\Manufacturing;

use App\Enums\DirectCostType;
use App\Models\Account;
use App\Models\ProductionDirectCost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One direct production cost (labour, power, ...) charged to WIP.
 * Authorization is the route's can:production_entries.edit.
 *
 * The credit account is limited to money and payable accounts
 * ({@see self::creditAccountQuery()}): a direct cost is paid in cash/bank or
 * left owing, never credited to an arbitrary income or inventory account.
 * The amount is validated as a plain 2dp string and never cast.
 */
class DirectCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::rulesFor('') + [
            // Correction reason: required by the model only when the date
            // falls in a closed year reopened for correction.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Rules with an optional dotted prefix, so the create form can validate
     * a `direct_costs.*` array with exactly the same constraints.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $prefix): array
    {
        $types = array_map(fn (DirectCostType $type) => $type->value, DirectCostType::cases());

        return [
            $prefix.'date' => ['required', 'date'],
            $prefix.'cost_type' => ['required', Rule::in($types)],
            $prefix.'description' => ['nullable', 'string', 'max:255'],
            $prefix.'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            $prefix.'credit_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn ($query) => $query->whereIn('id', self::creditAccountQuery()->select('accounts.id')->toBase()),
                ),
            ],
        ];
    }

    /**
     * The one definition lives on the model, which enforces it too.
     *
     * @return Builder<Account>
     */
    public static function creditAccountQuery(): Builder
    {
        return ProductionDirectCost::creditAccountQuery();
    }
}
