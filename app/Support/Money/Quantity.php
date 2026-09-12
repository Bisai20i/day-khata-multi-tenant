<?php

namespace App\Support\Money;

/**
 * An exact quantity, held at 4 decimals.
 *
 * Used for item quantities, rates, base quantities and unit conversion
 * factors - everything the schema stores as DECIMAL(x,4). See `DecimalValue`
 * for the rules that govern construction, rounding and comparison.
 *
 * Rates live here rather than in `Money` because a rate legitimately carries
 * 4 decimals (12.3456 per piece); the audit found rates being truncated to 2
 * on the way in and the reprint then disagreeing with the original bill.
 */
final class Quantity extends DecimalValue
{
    protected const SCALE = 4;

    /**
     * Quantities are shown to users without their padding zeros: "1.5", "2",
     * "0.125". Display only.
     */
    public function formatQuantity(): string
    {
        return $this->value->strippedOfTrailingZeros()->toString();
    }

    /**
     * Rates keep at least the 2 decimals users expect on a price ("12.50")
     * and show up to 4 when the rate really has them ("12.3456").
     */
    public function formatRate(): string
    {
        $stripped = $this->value->strippedOfTrailingZeros();

        return $stripped->toScale(max(2, $stripped->getScale()))->toString();
    }
}
