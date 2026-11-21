@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\NepaliCalendar;

    $periodFrom = $from ?? null;
    $periodTo = $to ?? null;
    $level = $level ?? 'ledger';
    $levelLabel = ['group' => 'Group', 'subgroup' => 'Sub group', 'ledger' => 'Ledger'][$level] ?? 'Ledger';
@endphp

@section('title', 'Trial Balance')

@section('doc-meta-extra')
    @if(!empty($fiscalYearName))
        <div><strong>Fiscal Year:</strong> {{ $fiscalYearName }}</div>
    @endif
    @if($periodFrom && $periodTo)
        <div>
            <strong>Period:</strong>
            {{ NepaliCalendar::formatBs($periodFrom) }} to {{ NepaliCalendar::formatBs($periodTo) }} (BS)
        </div>
        <div class="date-ad">{{ $periodFrom }} to {{ $periodTo }} (AD)</div>
    @endif
    <div><strong>Account:</strong> {{ $accountLabel ?? 'All accounts' }}</div>
    <div><strong>Level:</strong> {{ $levelLabel }}</div>
    @if(!empty($hideZero))
        <div>Zero balances hidden</div>
    @endif
    @if(!empty($branchFilterLabel))
        <div>{{ $branchFilterLabel }}</div>
    @endif
@endsection

@section('content')
    @php
        $money = fn (string $value): string => Money::of($value)->format();
        $amountKeys = ['openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit'];
        $ledgerLabel = fn (array $account): string => ! empty($account['code'])
            ? "{$account['code']} - {$account['name']}"
            : $account['name'];

        $accountCount = fn (array $node): int => count($node['accounts'] ?? [])
            + collect($node['subgroups'] ?? [])->sum(fn (array $sub): int => count($sub['accounts'] ?? []));

        // Flatten Head -> Group -> Sub-group -> Ledger into display rows. At the
        // Ledger level, empty nodes (no accounts beneath them) are skipped. At the
        // Group and Sub group levels the server has already emptied the children,
        // so every node it sent is kept and shown with its own totals.
        $pruned = $level !== 'ledger';
        $rows = [];

        foreach ($heads as $head) {
            $headAccounts = collect($head['groups'] ?? [])->sum(fn (array $group): int => $accountCount($group));

            if (! $pruned && $headAccounts === 0) {
                continue;
            }

            $rows[] = ['type' => 'head', 'label' => $head['name'], 'values' => null, 'indent' => 0];

            foreach ($head['groups'] ?? [] as $group) {
                if (! $pruned && $accountCount($group) === 0) {
                    continue;
                }

                if ($pruned && empty($group['accounts']) && empty($group['subgroups'])) {
                    $rows[] = ['type' => 'grouptotal', 'label' => $group['name'], 'values' => $group['totals'], 'indent' => 8];

                    continue;
                }

                $rows[] = ['type' => 'group', 'label' => $group['name'], 'values' => null, 'indent' => 8];

                foreach ($group['accounts'] ?? [] as $account) {
                    $rows[] = ['type' => 'ledger', 'label' => $ledgerLabel($account), 'values' => $account, 'indent' => 20];
                }

                foreach ($group['subgroups'] ?? [] as $subgroup) {
                    if (! $pruned && empty($subgroup['accounts'])) {
                        continue;
                    }

                    if ($pruned && empty($subgroup['accounts'])) {
                        $rows[] = ['type' => 'subtotal', 'label' => $subgroup['name'], 'values' => $subgroup['totals'], 'indent' => 20];

                        continue;
                    }

                    $rows[] = ['type' => 'subgroup', 'label' => $subgroup['name'], 'values' => null, 'indent' => 20];

                    foreach ($subgroup['accounts'] ?? [] as $account) {
                        $rows[] = ['type' => 'ledger', 'label' => $ledgerLabel($account), 'values' => $account, 'indent' => 32];
                    }

                    $rows[] = ['type' => 'subtotal', 'label' => $subgroup['name'].' Total', 'values' => $subgroup['totals'], 'indent' => 20];
                }

                $rows[] = ['type' => 'grouptotal', 'label' => $group['name'].' Total', 'values' => $group['totals'], 'indent' => 8];
            }

            $rows[] = ['type' => 'headtotal', 'label' => 'Total '.$head['name'], 'values' => $head['totals'], 'indent' => 0];
        }

        $rowStyle = [
            'head' => 'background: #e8e8e8; font-weight: bold;',
            'group' => 'background: #f5f5f5; font-weight: bold;',
            'subgroup' => 'font-weight: bold;',
            'ledger' => '',
            'subtotal' => 'background: #f0f6fb; font-weight: bold;',
            'grouptotal' => 'background: #fbf7e6; font-weight: bold;',
            'headtotal' => 'background: #e8e8e8; font-weight: bold;',
        ];

        $grandTotals = [
            $totalOpeningDebit, $totalOpeningCredit,
            $totalPeriodDebit, $totalPeriodCredit,
            $totalDebit, $totalCredit,
        ];

        $outOfBalance = array_values(array_filter([
            empty($openingInBalance) ? 'opening' : null,
            empty($periodInBalance) ? 'period' : null,
            empty($inBalance) ? 'closing' : null,
        ]));
    @endphp

    <style>
        .tb-table th,
        .tb-table td {
            font-size: 8px;
            padding-left: 3px;
            padding-right: 3px;
        }
    </style>

    <table class="items-table tb-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 28%;">Title</th>
                <th colspan="2" class="text-right" style="text-align: center;">Opening</th>
                <th colspan="2" class="text-right" style="text-align: center;">Period</th>
                <th colspan="2" class="text-right" style="text-align: center;">Closing</th>
            </tr>
            <tr>
                <th class="text-right" style="width: 12%;">Dr</th>
                <th class="text-right" style="width: 12%;">Cr</th>
                <th class="text-right" style="width: 12%;">Dr</th>
                <th class="text-right" style="width: 12%;">Cr</th>
                <th class="text-right" style="width: 12%;">Dr</th>
                <th class="text-right" style="width: 12%;">Cr</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr style="{{ $rowStyle[$row['type']] }}">
                    <td style="padding-left: {{ $row['indent'] }}px;">{{ $row['label'] }}</td>
                    @foreach($amountKeys as $key)
                        <td class="text-right">{{ $row['values'] === null ? '' : $money($row['values'][$key] ?? '0.00') }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #f0f0f0;">
                <td><strong>TOTAL</strong></td>
                @foreach($grandTotals as $grandTotal)
                    <td class="text-right"><strong>{{ $money($grandTotal) }}</strong></td>
                @endforeach
            </tr>
        </tfoot>
    </table>

    @if(empty($accountLabel))
        <p style="margin-top: 10px; font-size: 9.5px; font-weight: bold;">
            @if($outOfBalance === [])
                Balanced: opening, period and closing debits all equal their credits.
            @else
                NOT balanced: {{ implode('/', $outOfBalance) }} out
            @endif
        </p>
    @endif

    @include('pdf.partials.signatures')
@endsection
