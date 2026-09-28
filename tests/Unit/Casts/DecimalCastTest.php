<?php

use App\Casts\Decimal;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * A throwaway model with one Decimal-cast column, so the cast can be
 * exercised without a database.
 */
function decimalCastTestModel(): Model
{
    return new class extends Model
    {
        protected $guarded = [];

        protected function casts(): array
        {
            return ['total' => Decimal::class.':2'];
        }
    };
}

test('a Money assigned to a Decimal attribute reads back as the canonical string, not the cached object', function () {
    $model = decimalCastTestModel();
    $model->total = Money::of('1500.5');

    expect($model->total)->toBe('1500.50');
});

test('a plain string assigned to a Decimal attribute is normalised to its scale', function () {
    $model = decimalCastTestModel();
    $model->total = '7';

    expect($model->total)->toBe('7.00');
});
