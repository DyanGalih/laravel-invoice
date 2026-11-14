<?php

namespace Crater\Traits;

trait CalculatesDocumentTotals
{
    /**
     * Calculate document totals from line items, discounts, and taxes.
     */
    public function calculateDocumentTotals(array $items, $discountType = null, $discountVal = 0, array $taxes = []): array
    {
        $subTotal = 0;
        $totalTax = 0;

        foreach ($items as $item) {
            $qty = isset($item['quantity']) ? (float) $item['quantity'] : 1;
            $price = isset($item['price']) ? (float) $item['price'] : 0;
            $itemSubtotal = $qty * $price;
            $subTotal += $itemSubtotal;

            if (! empty($item['taxes']) && is_array($item['taxes'])) {
                foreach ($item['taxes'] as $tax) {
                    $taxAmount = isset($tax['amount']) ? (float) $tax['amount'] : 0;
                    $totalTax += $taxAmount;
                }
            }
        }

        if (! empty($taxes) && is_array($taxes)) {
            foreach ($taxes as $tax) {
                $taxAmount = isset($tax['amount']) ? (float) $tax['amount'] : 0;
                $totalTax += $taxAmount;
            }
        }

        $discount = 0;
        if ($discountType === 'percentage') {
            $discount = ($subTotal * (float) $discountVal) / 100;
        } elseif ($discountType === 'fixed') {
            $discount = (float) $discountVal;
        }

        $total = max(0, $subTotal - $discount + $totalTax);

        return [
            'sub_total' => (int) round($subTotal),
            'discount' => (float) $discount,
            'tax' => (int) round($totalTax),
            'total' => (int) round($total),
        ];
    }
}
