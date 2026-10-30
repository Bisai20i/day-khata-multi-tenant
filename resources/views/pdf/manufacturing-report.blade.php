@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\Money\Quantity;
@endphp

@section('title', $title ?? 'Manufacturing Report')

@section('doc-meta-extra')
    @if(($from ?? null) && ($to ?? null))
        <div><strong>Period:</strong> {{ $from }} to {{ $to }}</div>
    @endif
@endsection

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                @foreach($headings as $index => $heading)
                    <th class="{{ in_array($index, $numericColumns, true) ? 'text-right' : '' }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @php
                    $bold = in_array($row['style'], ['subtotal', 'total'], true);
                    $alert = $row['style'] === 'alert';
                @endphp
                <tr @if($alert) style="color: #a30000; font-weight: bold;" @endif>
                    @foreach($row['cells'] as $index => $cell)
                        <td class="{{ in_array($index, $numericColumns, true) ? 'text-right' : '' }}">
                            @php
                                $text = match (true) {
                                    $cell === null => '',
                                    $cell instanceof Money => $cell->format(),
                                    $cell instanceof Quantity => $cell->formatQuantity(),
                                    default => (string) $cell,
                                };
                            @endphp
                            @if($bold)<strong>{{ $text }}</strong>@else{{ $text }}@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
