<?php

namespace App\Enums;

use App\Support\Manufacturing\ManufacturingAccounts;

/**
 * Which accounting model an item follows (hybrid perpetual inventory).
 *
 * Trading items stay periodic exactly as before: no stock document posts a
 * journal and the year-end close posts the trading entries. Raw material and
 * finished good items are perpetual: every movement is valued
 * (item_stock_movements.cost_value) and posts to its class account in real
 * time, so nothing is ever valued twice.
 */
enum InventoryClass: string
{
    case Trading = 'trading';
    case RawMaterial = 'raw_material';
    case FinishedGood = 'finished_good';

    /**
     * Whether stock of this class is valued and posted to the GL in real time.
     */
    public function isPerpetual(): bool
    {
        return $this !== self::Trading;
    }

    /**
     * Code of the balance-sheet account holding this class's value, null for
     * trading items (their stock only reaches the books at year-end).
     */
    public function inventoryAccountCode(): ?string
    {
        return match ($this) {
            self::Trading => null,
            self::RawMaterial => ManufacturingAccounts::RAW_MATERIAL,
            self::FinishedGood => ManufacturingAccounts::FINISHED_GOODS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trading => 'Trading',
            self::RawMaterial => 'Raw Material',
            self::FinishedGood => 'Finished Good',
        };
    }
}
