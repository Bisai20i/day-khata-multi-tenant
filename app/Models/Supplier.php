<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_id', 'name', 'address', 'mobile_no', 'email', 'tpin', 'is_vat_registered'])]
class Supplier extends Model
{
    use HasFactory, HasLedgerAccount;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Drives the default state of Purchases/Create.vue's PAN /
            // non-VAT toggle: a supplier who is not VAT registered can only
            // issue a PAN bill, so a purchase against them opens with
            // force_non_taxable already checked.
            'is_vat_registered' => 'boolean',
        ];
    }

    protected function ledgerAccountSubgroupName(): string
    {
        return 'Sundry Creditors';
    }
}
