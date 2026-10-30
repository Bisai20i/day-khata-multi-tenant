<?php

use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\Manufacturing\ManufacturingAccounts;

test('only the two manufacturing classes are perpetual', function () {
    expect(InventoryClass::Trading->isPerpetual())->toBeFalse()
        ->and(InventoryClass::RawMaterial->isPerpetual())->toBeTrue()
        ->and(InventoryClass::FinishedGood->isPerpetual())->toBeTrue();
});

test('each class maps to its inventory account code', function () {
    expect(InventoryClass::Trading->inventoryAccountCode())->toBeNull()
        ->and(InventoryClass::RawMaterial->inventoryAccountCode())->toBe('AS12')
        ->and(InventoryClass::FinishedGood->inventoryAccountCode())->toBe('AS14')
        ->and(InventoryClass::RawMaterial->inventoryAccountCode())->toBe(ManufacturingAccounts::RAW_MATERIAL)
        ->and(InventoryClass::FinishedGood->inventoryAccountCode())->toBe(ManufacturingAccounts::FINISHED_GOODS);
});

test('inventory class values and labels match the stored strings', function () {
    expect(InventoryClass::from('trading'))->toBe(InventoryClass::Trading)
        ->and(InventoryClass::from('raw_material'))->toBe(InventoryClass::RawMaterial)
        ->and(InventoryClass::from('finished_good'))->toBe(InventoryClass::FinishedGood)
        ->and(InventoryClass::RawMaterial->label())->toBe('Raw Material')
        ->and(InventoryClass::FinishedGood->label())->toBe('Finished Good');
});

test('the manufacturing movement types have the contracted direction', function () {
    expect(StockMovementType::MaterialIssue->direction())->toBe(-1)
        ->and(StockMovementType::MaterialReturn->direction())->toBe(1)
        ->and(StockMovementType::ProductionReceipt->direction())->toBe(1)
        ->and(StockMovementType::MaterialIssue->value)->toBe('material_issue')
        ->and(StockMovementType::MaterialReturn->value)->toBe('material_return')
        ->and(StockMovementType::ProductionReceipt->value)->toBe('production_receipt');
});

test('the manufacturing voucher types exist and are not manually cancellable', function () {
    foreach ([VoucherType::MaterialIssue, VoucherType::Production, VoucherType::ProductionEntry, VoucherType::InventoryCost] as $type) {
        expect(VoucherType::tryFrom($type->value))->toBe($type)
            ->and(VoucherType::manuallyCancellableTypes())->not->toContain($type);
    }
});

test('manufacturing account codes are the contracted ones', function () {
    expect(ManufacturingAccounts::WIP)->toBe('AS13')
        ->and(ManufacturingAccounts::COGS)->toBe('EXE10')
        ->and(ManufacturingAccounts::ADJUSTMENT)->toBe('EXE23')
        ->and(ManufacturingAccounts::PRODUCTION_LOSS)->toBe('EXE24');
});
