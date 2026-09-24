{{-- Items grid with the legacy columns: S.N., HS Code, description, Qty, Rate,
     Total. The Total column is the line before its own discount, so rate x qty
     visibly equals it and the Discount row below accounts for the difference.
     A Free column appears only on a bill that gave units away. --}}
@php
    use App\Support\Money\Money;
    use App\Support\Money\Quantity;

    $anyBonus = $sale->lines->contains(fn ($line) => Quantity::of($line->bonus_quantity ?? '0')->isPositive());
@endphp
<table class="items">
    <thead>
        <tr>
            <th style="width: 6%;">S.N.</th>
            <th style="width: 14%;">HS Code</th>
            <th>{{ $descriptionLabel }}</th>
            <th style="width: 11%;">Qty</th>
            @if($anyBonus)
                <th style="width: 8%;">Free</th>
            @endif
            <th style="width: 13%;">Rate (Rs.)</th>
            <th style="width: 15%;">Total (Rs.)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sale->lines as $index => $line)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td class="center">{{ filled($line->item->hs_code) ? $line->item->hs_code : '-' }}</td>
                <td>{{ $line->item->name }}</td>
                <td class="num">{{ Quantity::of($line->quantity)->formatQuantity() }} {{ $line->itemUnit?->name ?? $line->item->unit }}</td>
                @if($anyBonus)
                    <td class="num">{{ Quantity::of($line->bonus_quantity ?? '0')->formatQuantity() }}</td>
                @endif
                <td class="num">{{ Quantity::of($line->rate)->formatRate() }}</td>
                <td class="num">{{ Money::of($line->line_total)->plus(Money::of($line->discount_amount))->format() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
