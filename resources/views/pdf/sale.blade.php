<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ ['abbreviated' => 'Abbreviated Tax Invoice', 'pan' => 'PAN Invoice'][$sale->invoice_type] ?? 'Tax Invoice' }} {{ $documentNumber }}</title>
@include('pdf.ird._styles')
</head>
<body>
@php
    use App\Support\Money\Money;

    // The three IRD-approved formats live in pdf/ird/{tax,pan,abbreviated},
    // ported from the legacy day_khata bills/ views. This view only works out
    // the figures once and picks the format, so the layout files stay pure
    // markup.
    $format = match ($sale->invoice_type) {
        'abbreviated' => 'abbreviated',
        'pan' => 'pan',
        default => 'tax',
    };

    // Everything below is read from the stored row, never recomputed: the
    // 2026-09-11 audit found the printed bill disagreeing with the stored one
    // (P0-8) and the taxable line printing before the discount was applied.
    $headerDiscount = Money::of($sale->discount_amount);
    $taxable = Money::of($sale->taxable_amount);
    $nontaxable = Money::of($sale->nontaxable_amount);
    $vat = Money::of($sale->vat_amount);
    $tds = Money::of($sale->tds_amount);
    $total = Money::of($sale->total);

    // The legacy bill has one Discount row, so it is every discount on the
    // document: each line's own plus the header one. Sub Total is then what the
    // lines came to before any of it (C3 steps 4 and 5 give the group totals
    // as the lines net of the header discount).
    $lineDiscounts = $sale->lines->reduce(
        fn (Money $carry, $line) => $carry->plus(Money::of($line->discount_amount)),
        Money::zero(),
    );
    $totalDiscount = $headerDiscount->plus($lineDiscounts);
    $grossSubtotal = $taxable->plus($nontaxable)->plus($totalDiscount);
    $netPayable = $total->minus($tds);

    // Whatever a partial sale left on account, so Credit is ticked for it.
    $dueOnAccount = $netPayable
        ->minus(Money::of($sale->cash_amount ?? '0'))
        ->minus(Money::of($sale->bank_amount ?? '0'))
        ->isPositive();

    // Buyer as the invoice was issued to (C7). Old rows have no snapshot, so
    // they fall back to the live customer, which is what they always printed.
    $buyerName = $sale->buyer_name ?? $sale->customer->name;
    $buyerPan = $sale->buyer_pan ?? $sale->customer->tpin;
    $buyerAddress = $sale->buyer_address ?? $sale->customer->address;

    // Contract C9 print-compliance variables, each with a safe default so a
    // caller that has not been wired up yet still renders.
    $printDateAd = $dateAd ?? $documentDate;
    $printDateBs = $dateBs ?? \App\Support\NepaliCalendar::formatBs($printDateAd);
    $printFiscalYearName = $fiscalYearName ?? null;
    $printAmountInWords = $amountInWords ?? null;

    // BS first, AD beside it: the Bikram Sambat date is the one the IRD reads.
    $dateLabel = $printDateBs !== '' ? "{$printDateBs} (AD {$printDateAd})" : $printDateAd;
@endphp
@include('pdf.ird.'.$format)
</body>
</html>
