@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', $title)

{{-- The Fiscal Year line itself comes from pdf.layout ($fiscalYearName). --}}
@section('doc-meta-extra')
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
        $columnCount = $isGrouped ? 6 : 7;
        $serial = 0;

        // Dr/Cr for a signed balance held on the party's normal side:
        // Debtors positive = Dr, Creditors positive = Cr; zero has no side.
        $sideOf = function (string $balance) use ($creditNormal): string {
            $amount = Money::of($balance);

            if ($amount->isZero()) {
                return '';
            }

            $isPositive = $amount->isPositive();

            if ($creditNormal) {
                return $isPositive ? 'Cr' : 'Dr';
            }

            return $isPositive ? 'Dr' : 'Cr';
        };

        $emptyMessage = $creditNormal
            ? 'No supplier is owed a balance in this fiscal year.'
            : 'No customer owes a balance in this fiscal year.';

        $anyOverpaid = false;
        $allRows = $isGrouped
            ? collect($groups)->flatMap(fn ($group) => $group['rows'])
            : collect($rows);

        foreach ($allRows as $candidate) {
            if (! empty($candidate['is_overpaid'])) {
                $anyOverpaid = true;
                break;
            }
        }
    @endphp

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">SN</th>
                <th>{{ $partyLabel }}</th>
                <th>Address</th>
                <th>Mobile</th>
                @unless($isGrouped)
                    <th>Area</th>
                @endunless
                <th class="text-right" style="width: 14%;">Balance</th>
                <th class="text-center" style="width: 7%;">Dr/Cr</th>
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
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['address'] ?? '' }}</td>
                            <td>{{ $row['mobile_no'] ?? '' }}</td>
                            <td class="text-right">{{ Money::of($row['balance'])->abs()->format() }}</td>
                            <td class="text-center">{{ $sideOf($row['balance']) }}</td>
                        </tr>
                    @endforeach
                    <tr style="background: #f0f0f0; font-weight: bold;">
                        <td colspan="{{ $columnCount - 2 }}" class="text-right"><strong>Subtotal - {{ $group['area'] }}</strong></td>
                        <td class="text-right"><strong>{{ Money::of($group['totals']['balance'] ?? '0.00')->abs()->format() }}</strong></td>
                        <td class="text-center"><strong>{{ $sideOf($group['totals']['balance'] ?? '0.00') }}</strong></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columnCount }}" class="text-center">{{ $emptyMessage }}</td>
                    </tr>
                @endforelse
            @else
                @forelse($rows as $row)
                    @php $serial++; @endphp
                    <tr>
                        <td>{{ $serial }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['address'] ?? '' }}</td>
                        <td>{{ $row['mobile_no'] ?? '' }}</td>
                        <td>{{ $row['area'] ?? '' }}</td>
                        <td class="text-right">{{ Money::of($row['balance'])->abs()->format() }}</td>
                        <td class="text-center">{{ $sideOf($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columnCount }}" class="text-center">{{ $emptyMessage }}</td>
                    </tr>
                @endforelse
            @endif
        </tbody>
        <tfoot>
            <tr style="background: #f0f0f0;">
                <td colspan="{{ $columnCount - 2 }}" class="text-right"><strong>Total</strong></td>
                <td class="text-right"><strong>{{ Money::of($total)->abs()->format() }}</strong></td>
                <td class="text-center"><strong>{{ $sideOf($total) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    @if($anyOverpaid)
        <div class="narration">Parties shown with the opposite side are overpaid.</div>
    @endif
@endsection
