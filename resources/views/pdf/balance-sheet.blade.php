@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\NepaliCalendar;

    $asAt = $to ?? null;
@endphp

@section('title', 'Balance Sheet')

@section('doc-meta-extra')
    @if(!empty($fiscalYearName))
        <div><strong>Fiscal Year:</strong> {{ $fiscalYearName }}</div>
    @endif
    @if($asAt)
        <div>
            <strong>As at:</strong> {{ NepaliCalendar::formatBs($asAt) }} (BS)
        </div>
        <div class="date-ad">{{ $asAt }} (AD)</div>
    @endif
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
        $ledgerLabel = fn (array $account): string => ! empty($account['code'])
            ? "{$account['code']} - {$account['name']}"
            : $account['name'];

        // Flatten one head (Group -> Sub-group -> Ledger) into display rows.
        // Every sub-group, group and head closes with a bold "<name> Total".
        $headRows = function (array $head, bool $withHeadTotal) use ($ledgerLabel): array {
            $rows = [['type' => 'head', 'label' => $head['name'], 'amount' => null, 'indent' => 0]];

            foreach ($head['groups'] as $group) {
                $rows[] = ['type' => 'group', 'label' => $group['name'], 'amount' => null, 'indent' => 6];

                foreach ($group['accounts'] as $account) {
                    $rows[] = ['type' => 'ledger', 'label' => $ledgerLabel($account), 'amount' => $account['amount'], 'indent' => 12];
                }

                foreach ($group['subgroups'] as $subgroup) {
                    $rows[] = ['type' => 'subgroup', 'label' => $subgroup['name'], 'amount' => null, 'indent' => 12];

                    foreach ($subgroup['accounts'] as $account) {
                        $rows[] = ['type' => 'ledger', 'label' => $ledgerLabel($account), 'amount' => $account['amount'], 'indent' => 20];
                    }

                    $rows[] = ['type' => 'subtotal', 'label' => $subgroup['name'].' Total', 'amount' => $subgroup['total'], 'indent' => 12];
                }

                $rows[] = ['type' => 'grouptotal', 'label' => $group['name'].' Total', 'amount' => $group['total'], 'indent' => 6];
            }

            if ($withHeadTotal) {
                $rows[] = ['type' => 'headtotal', 'label' => $head['name'].' Total', 'amount' => $head['total'], 'indent' => 0];
            }

            return $rows;
        };

        // Two-sided sheet: Liabilities & Equity (every head except Assets, in the
        // server order, Current Year Profit / (Loss) already sits inside Capital)
        // on the left, Assets on the right. The Assets head's own heading and
        // total are left out because "Total Assets" closes the right side.
        $assetHeads = collect($heads)->filter(fn (array $head): bool => $head['name'] === 'Assets')->values();
        $otherHeads = collect($heads)->filter(fn (array $head): bool => $head['name'] !== 'Assets')->values();

        $difference = Money::of($totalAssets)->minus(Money::of($totalLiabilitiesAndCapital));
        $isBalanced = empty($balanceWarning);

        $leftRows = [];
        foreach ($otherHeads as $head) {
            foreach ($headRows($head, true) as $row) {
                $leftRows[] = $row;
            }
        }

        $rightRows = [];
        foreach ($assetHeads as $head) {
            foreach ($headRows($head, false) as $row) {
                if ($row['type'] !== 'head') {
                    $rightRows[] = $row;
                }
            }
        }

        $emptyNote = ['type' => 'note', 'label' => 'Nothing to show', 'amount' => null, 'indent' => 0];
        $leftRows = $leftRows === [] ? [$emptyNote] : $leftRows;
        $rightRows = $rightRows === [] ? [$emptyNote] : $rightRows;
        $rowCount = max(count($leftRows), count($rightRows));

        $rowStyle = [
            'head' => 'background: #e8e8e8; font-weight: bold;',
            'group' => 'background: #f5f5f5; font-weight: bold;',
            'subgroup' => 'font-weight: bold;',
            'ledger' => '',
            'subtotal' => 'background: #f0f6fb; font-weight: bold;',
            'grouptotal' => 'background: #fbf7e6; font-weight: bold;',
            'headtotal' => 'background: #e8e8e8; font-weight: bold;',
            'note' => 'color: #777; font-style: italic;',
        ];
        // Padding cell of the shorter side: no horizontal borders, so it reads as blank space.
        $padStyle = 'border-top: none; border-bottom: none;';
    @endphp

    @if(! empty($stock) && ($stock['posted'] ?? true) === false && ! empty($stock['asOf']))
        <div class="legal-note">
            Stock in Hand is valued live at {{ $money($stock['closing']) }} as at {{ $stock['asOf'] }}, because this
            fiscal year has not been closed yet. Closing the year posts exactly that amount.
        </div>
    @endif

    @if(! $isBalanced)
        <div class="legal-note" style="font-weight: bold;">
            Balance Sheet is NOT balanced. {{ $balanceWarning }}
            (Total Assets {{ $money($totalAssets) }} | Total Liabilities &amp; Equity {{ $money($totalLiabilitiesAndCapital) }}
            | Difference {{ $money($difference->abs()->toString()) }})
        </div>
    @endif

    <table class="items-table" style="width: 100%; table-layout: fixed; border-collapse: collapse;">
        <colgroup>
            <col style="width: 33%;">
            <col style="width: 17%;">
            <col style="width: 33%;">
            <col style="width: 17%;">
        </colgroup>
        <thead>
            <tr>
                <th>Liabilities &amp; Equity</th>
                <th style="text-align: right;">Amount</th>
                <th style="border-left: 2px solid #999;">Assets</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @for($i = 0; $i < $rowCount; $i++)
                @php
                    $left = $leftRows[$i] ?? null;
                    $right = $rightRows[$i] ?? null;
                @endphp
                <tr style="page-break-inside: avoid;">
                    @if($left)
                        <td style="{{ $rowStyle[$left['type']] }} padding-left: {{ $left['indent'] + 6 }}px;">{{ $left['label'] }}</td>
                        <td class="text-right" style="{{ $rowStyle[$left['type']] }}">{{ $left['amount'] === null ? '' : $money($left['amount']) }}</td>
                    @else
                        <td style="{{ $padStyle }}">&nbsp;</td>
                        <td style="{{ $padStyle }}">&nbsp;</td>
                    @endif
                    @if($right)
                        <td style="{{ $rowStyle[$right['type']] }} border-left: 2px solid #999; padding-left: {{ $right['indent'] + 6 }}px;">{{ $right['label'] }}</td>
                        <td class="text-right" style="{{ $rowStyle[$right['type']] }}">{{ $right['amount'] === null ? '' : $money($right['amount']) }}</td>
                    @else
                        <td style="{{ $padStyle }} border-left: 2px solid #999;">&nbsp;</td>
                        <td style="{{ $padStyle }}">&nbsp;</td>
                    @endif
                </tr>
            @endfor
            <tr style="background: #f0f0f0; page-break-inside: avoid;">
                <td style="border-top: 2px solid #333;"><strong>Total Liabilities &amp; Equity</strong></td>
                <td class="text-right" style="border-top: 2px solid #333;"><strong>{{ $money($totalLiabilitiesAndCapital) }}</strong></td>
                <td style="border-top: 2px solid #333; border-left: 2px solid #999;"><strong>Total Assets</strong></td>
                <td class="text-right" style="border-top: 2px solid #333;"><strong>{{ $money($totalAssets) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 10px; font-size: 9.5px; font-weight: bold;">
        @if($isBalanced)
            Balance Sheet is balanced: Total Assets = Total Liabilities &amp; Equity = {{ $money($totalAssets) }}
        @else
            Balance Sheet is NOT balanced: difference {{ $money($difference->abs()->toString()) }}
        @endif
    </p>

    @include('pdf.partials.signatures')
@endsection
