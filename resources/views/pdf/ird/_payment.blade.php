{{-- Payment Method tick boxes. A partial sale ticks every leg it was settled
     through, and Credit for the part left on account. --}}
@php
    use App\Support\Money\Money;

    $mode = $sale->payment_mode;
    $partial = $mode === 'partial';
    $ticks = [
        'Cash' => $mode === 'cash' || ($partial && Money::of($sale->cash_amount ?? '0')->isPositive()),
        'Cheque' => $mode === 'cheque',
        'Credit' => $mode === 'credit' || ($partial && $dueOnAccount),
        $bankLabel => in_array($mode, ['bank', 'bank transfer'], true) || ($partial && Money::of($sale->bank_amount ?? '0')->isPositive()),
    ];
@endphp
<td class="payment-block">
    <strong>Payment Method :</strong>
    <table class="payment-options" style="width: auto;">
        <tr>
            @foreach($ticks as $label => $on)
                <td><span class="check-box">{{ $on ? '✓' : '' }}</span> {{ $label }}</td>
            @endforeach
        </tr>
    </table>
</td>
