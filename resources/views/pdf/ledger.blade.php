@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\NepaliCalendar;

    $periodFrom = $from ?? null;
    $periodTo = $to ?? null;
@endphp

@section('title', $title ?? 'Ledger')

@section('doc-meta-extra')
    @if(!empty($branchFilterLabel ?? null))
        <div>{{ $branchFilterLabel }}</div>
    @endif
    @if(!empty($projectLabel ?? null))
        <div><strong>Project:</strong> {{ $projectLabel }}</div>
    @endif
    @if(!empty($partyArea ?? null))
        <div><strong>Area:</strong> {{ $partyArea }}</div>
    @endif
    @if($periodFrom && $periodTo)
        <div>
            <strong>Period:</strong>
            {{ NepaliCalendar::formatBs($periodFrom) }} to {{ NepaliCalendar::formatBs($periodTo) }} (BS)
        </div>
        <div class="date-ad">{{ $periodFrom }} to {{ $periodTo }} (AD)</div>
    @endif
    @if(!empty($includesSubLedgers ?? false))
        <div>Includes sub-ledgers</div>
    @endif
@endsection

@section('content')
    @php
        $money = fn (string $value): string => Money::of($value)->format();

        // A balance prints as its absolute amount plus the Dr/Cr side; a zero
        // balance prints "0.00" with no side.
        $balanceText = function (string $value, ?string $side): string {
            $amount = Money::of($value);
            $text = $amount->abs()->format();

            return $amount->isZero() || empty($side) ? $text : $text.' '.$side;
        };

        // A zero debit/credit cell stays blank rather than printing 0.00.
        $amountCell = fn (string $value): string => Money::of($value)->isZero() ? '' : Money::of($value)->format();
    @endphp

    <style>
        .ledger-table th,
        .ledger-table td {
            font-size: 7px;
            padding: 3px 2px;
            vertical-align: top;
            word-wrap: break-word;
        }

        .ledger-table th {
            font-size: 7px;
        }
    </style>

    {{-- Particulars is dropped when no entry carries it (the party ledger of a user without the general ledger permission), as on screen. --}}
    @php($showParticulars = empty($entries) || array_key_exists('particulars', $entries[array_key_first($entries)]))
    @php($columnCount = $showParticulars ? 10 : 9)
    <table class="items-table ledger-table">
        <thead>
            <tr>
                <th style="width: 7%;">Date (BS)</th>
                <th style="width: 7%;">Date (AD)</th>
                <th style="width: 9%;">Voucher</th>
                <th style="width: 7%;">Reference</th>
                @if($showParticulars)
                    <th style="width: 13%;">Particulars</th>
                @endif
                <th style="width: {{ $showParticulars ? 12 : 18 }}%;">Narration</th>
                <th style="width: {{ $showParticulars ? 10 : 16 }}%;">Main Narration</th>
                <th class="text-right" style="width: 11%;">Debit</th>
                <th class="text-right" style="width: 11%;">Credit</th>
                <th class="text-right" style="width: 13%;">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background: #f5f5f5; font-weight: bold;">
                <td colspan="{{ $columnCount - 1 }}"><strong>Opening Balance</strong></td>
                <td class="text-right"><strong>{{ $balanceText($openingBalance, $openingSide ?? null) }}</strong></td>
            </tr>
            @forelse($entries as $entry)
                <tr>
                    <td>{{ $entry['dateBs'] ?? '' }}</td>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ strtoupper(str_replace('_', ' ', $entry['voucherType'])) }} #{{ $entry['voucherNumber'] }}</td>
                    <td>{{ $entry['reference'] ?? ($entry['document']['label'] ?? '') }}</td>
                    @if($showParticulars)
                        <td>{{ $entry['particulars'] ?? '' }}</td>
                    @endif
                    <td>{{ $entry['narration'] ?? '' }}</td>
                    <td>{{ $entry['mainNarration'] ?? '' }}</td>
                    <td class="text-right">{{ $amountCell($entry['debit']) }}</td>
                    <td class="text-right">{{ $amountCell($entry['credit']) }}</td>
                    <td class="text-right">{{ $balanceText($entry['balance'], $entry['side'] ?? null) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}" class="text-center">No ledger entries found for the selected account and date range.</td>
                </tr>
            @endforelse
            <tr style="background: #f0f0f0; font-weight: bold;">
                <td colspan="{{ $columnCount - 3 }}"><strong>Total</strong></td>
                <td class="text-right"><strong>{{ $money($periodDebit) }}</strong></td>
                <td class="text-right"><strong>{{ $money($periodCredit) }}</strong></td>
                <td></td>
            </tr>
            <tr style="background: #f5f5f5; font-weight: bold;">
                <td colspan="{{ $columnCount - 1 }}"><strong>Closing Balance</strong></td>
                <td class="text-right"><strong>{{ $balanceText($closingBalance, $closingSide ?? null) }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if(!empty($subLedgerSummary['rows'] ?? []))
        @php
            // Same rule as the ledger table: absolute amount plus the side, zero without a side.
            $sideText = fn (string $value, ?string $side): string => Money::of($value)->isZero() || empty($side)
                ? Money::of($value)->abs()->format()
                : Money::of($value)->abs()->format().' '.$side;
        @endphp

        <div style="margin-top: 16px; font-weight: bold; font-size: 9px;">Sub-ledger summary</div>
        <table class="items-table ledger-table" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th style="width: 36%;">Sub-ledger</th>
                    <th class="text-right" style="width: 16%;">Opening</th>
                    <th class="text-right" style="width: 16%;">Debit</th>
                    <th class="text-right" style="width: 16%;">Credit</th>
                    <th class="text-right" style="width: 16%;">Closing</th>
                </tr>
            </thead>
            <tbody>
                @foreach($subLedgerSummary['rows'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}{{ !empty($row['code']) ? ' ('.$row['code'].')' : '' }}</td>
                        <td class="text-right">{{ $sideText($row['openingBalance'], $row['openingSide']) }}</td>
                        <td class="text-right">{{ $amountCell($row['debit']) }}</td>
                        <td class="text-right">{{ $amountCell($row['credit']) }}</td>
                        <td class="text-right">{{ $sideText($row['closingBalance'], $row['closingSide']) }}</td>
                    </tr>
                @endforeach
                @if(!empty($subLedgerSummary['total']))
                    @php($summaryTotal = $subLedgerSummary['total'])
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td><strong>Total</strong></td>
                        <td class="text-right"><strong>{{ $sideText($summaryTotal['openingBalance'], $summaryTotal['openingSide']) }}</strong></td>
                        <td class="text-right"><strong>{{ $money($summaryTotal['debit']) }}</strong></td>
                        <td class="text-right"><strong>{{ $money($summaryTotal['credit']) }}</strong></td>
                        <td class="text-right"><strong>{{ $sideText($summaryTotal['closingBalance'], $summaryTotal['closingSide']) }}</strong></td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    @include('pdf.partials.signatures')
@endsection
