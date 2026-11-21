@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\NepaliCalendar;
@endphp

@section('title', $title)

{{-- The Fiscal Year line itself comes from pdf.layout ($fiscalYearName). --}}
@section('doc-meta-extra')
    <div>
        <strong>As of (BS):</strong> {{ NepaliCalendar::formatBs($asOf) }}
        <span class="date-ad">(AD {{ $asOf }})</span>
    </div>
    @if(!empty($partyName))
        <div><strong>{{ $partyLabel }}:</strong> {{ $partyName }}</div>
    @endif
    @if(!empty($areaLabel))
        <div><strong>Area:</strong> {{ $areaLabel }}</div>
    @endif
    @if(!empty($branchFilterLabel))
        <div>{{ $branchFilterLabel }}</div>
    @endif
@endsection

@section('content')
    @php
        $isGrouped = $groups !== null;
        // SN, party, contact, [area], oldest unpaid, days, then six amounts.
        $labelSpan = $isGrouped ? 5 : 6;
        $columnCount = $labelSpan + 6;
        $serial = 0;
        $amountKeys = ['opening', 'current', 'days31_60', 'days61_90', 'days90Plus', 'total'];
    @endphp

    <style>
        /* The layout's @page size wins over ->setPaper() in Dompdf, so landscape is declared here. */
        @page {
            size: A4 landscape;
            margin: 28px 32px;
        }

        .aged-table tr {
            page-break-inside: avoid;
        }

        .aged-table th,
        .aged-table td {
            padding: 4px 4px;
            font-size: 8px;
        }
    </style>

    <table class="items-table aged-table">
        <thead>
            <tr>
                <th style="width: 3%;">SN</th>
                <th>{{ $partyLabel }}</th>
                <th>Contact</th>
                @unless($isGrouped)
                    <th>Area</th>
                @endunless
                <th>Oldest unpaid</th>
                <th class="text-right">Days</th>
                <th class="text-right">Opening / unallocated</th>
                <th class="text-right">0-30</th>
                <th class="text-right">31-60</th>
                <th class="text-right">61-90</th>
                <th class="text-right">90+</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @if($isGrouped)
                @forelse($groups as $group)
                    <tr style="background: #f5f5f5; font-weight: bold;">
                        <td colspan="{{ $columnCount }}"><strong>Area: {{ $group['area'] }}</strong></td>
                    </tr>
                    @foreach($group['rows'] as $row)
                        @php $serial++; @endphp
                        <tr>
                            <td>{{ $serial }}</td>
                            <td>{{ $row['party'] }}</td>
                            <td>{{ $row['mobile_no'] ?? '' }}</td>
                            <td>{{ $row['oldest'] ?? '' }}</td>
                            <td class="text-right">{{ $row['days'] ?? '' }}</td>
                            @foreach($amountKeys as $key)
                                <td class="text-right">{{ Money::of($row[$key])->format() }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td colspan="{{ $labelSpan }}" class="text-right"><strong>Subtotal - {{ $group['area'] }}</strong></td>
                        @foreach($amountKeys as $key)
                            <td class="text-right"><strong>{{ Money::of($group['totals'][$key] ?? '0.00')->format() }}</strong></td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columnCount }}" class="text-center">Nothing outstanding as of this date.</td>
                    </tr>
                @endforelse
            @else
                @forelse($rows as $row)
                    @php $serial++; @endphp
                    <tr>
                        <td>{{ $serial }}</td>
                        <td>{{ $row['party'] }}</td>
                        <td>{{ $row['mobile_no'] ?? '' }}</td>
                        <td>{{ $row['area'] ?? '' }}</td>
                        <td>{{ $row['oldest'] ?? '' }}</td>
                        <td class="text-right">{{ $row['days'] ?? '' }}</td>
                        @foreach($amountKeys as $key)
                            <td class="text-right">{{ Money::of($row[$key])->format() }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columnCount }}" class="text-center">Nothing outstanding as of this date.</td>
                    </tr>
                @endforelse
            @endif
        </tbody>
        <tfoot>
            <tr style="background: #f0f0f0;">
                <td colspan="{{ $labelSpan }}" class="text-right"><strong>Total</strong></td>
                @foreach($amountKeys as $key)
                    <td class="text-right"><strong>{{ Money::of($totals[$key] ?? '0.00')->format() }}</strong></td>
                @endforeach
            </tr>
            <tr>
                <td colspan="{{ $labelSpan + 5 }}" class="text-right">Advances (credit balances, not in Total)</td>
                <td class="text-right">{{ Money::of($advances ?? '0.00')->format() }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="narration">
        Each {{ strtolower($partyLabel) }}'s ledger balance as of this date, in this date's fiscal year: payments, receipts
        and returns dated on or before it are applied to the oldest entries first (FIFO), and what remains is aged from
        the voucher date (0-30, 31-60, 61-90 and over 90 days). Opening / unallocated is the part of the year's opening
        balance still unsettled. Parties in credit (advances) are not listed; their total is shown as Advances.
    </div>
@endsection
