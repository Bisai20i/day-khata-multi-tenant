{{-- Amount in words (and any narration) under the totals, as on the legacy bill. --}}
@if($printAmountInWords || $sale->narration)
    <div class="words-remarks section">
        @if($printAmountInWords)
            <div class="row"><strong>Amount in Words :</strong> {{ $printAmountInWords }}</div>
        @endif
        @if($sale->narration)
            <div class="row"><strong>Narration :</strong> {{ $sale->narration }}</div>
        @endif
    </div>
@endif
