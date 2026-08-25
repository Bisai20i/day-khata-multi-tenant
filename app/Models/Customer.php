<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_id', 'name', 'address', 'mobile_no', 'email', 'tpin', 'citizenship'])]
class Customer extends Model
{
    use HasFactory, HasLedgerAccount;

    protected function ledgerAccountSubgroupName(): string
    {
        return 'Sundry Debtors';
    }
}
