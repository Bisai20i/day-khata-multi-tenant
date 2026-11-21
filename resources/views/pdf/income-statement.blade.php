@extends('pdf.layout')

@php
    use App\Support\Money\Money;
    use App\Support\NepaliCalendar;

    $hasStatement = ! empty($statement ?? null);
    $periodFrom = $from ?? null;
    $periodTo = $to ?? null;
@endphp

@section('title', 'Income Statement')

@section('doc-meta-extra')
    @if($periodFrom && $periodTo)
        <div>
            <strong>Period:</strong>
            {{ NepaliCalendar::formatBs($periodFrom) }} to {{ NepaliCalendar::formatBs($periodTo) }} (BS)
        </div>
        <div class="date-ad">{{ $periodFrom }} to {{ $periodTo }} (AD)</div>
    @endif
    @if(!empty($projectLabel))
        <div><strong>Project:</strong> {{ $projectLabel }}</div>
    @endif
    @if(!empty($branchFilterLabel))
        <div>{{ $branchFilterLabel }}</div>
    @endif
@endsection

@section('content')
    @if($hasStatement)
        @php
            $money = fn (string $value): string => Money::of($value)->format();
            $deduction = fn (string $value): string => '('.Money::of($value)->format().')';

            // A result line shows a loss in parentheses (compared as exact
            // decimals, never floats), a profit as a plain figure.
            $signed = fn (string $value): string => Money::of($value)->isNegative()
                ? '('.Money::of($value)->abs()->format().')'
                : Money::of($value)->format();

            $revenue = $statement['revenue'];
            $cost = $statement['costOfSales'];
            $otherIncome = $statement['otherIncome'];
            $expenseSection = $statement['expenses'];
            $projectFiltered = ! empty($stock['projectFiltered']);
        @endphp

        @if($projectFiltered)
            <div class="legal-note">
                Only lines posted to the selected project are included. Stock is not valued per project, so opening
                and closing stock are zero.
            </div>
        @elseif(! empty($stock) && ($stock['posted'] ?? true) === false && ! empty($stock['asOf']))
            <div class="legal-note">
                @if(! empty($stock['yearPosted']))
                    Stock is valued live for this date range: opening
                    {{ ! empty($stock['openingAsOf']) ? 'as at '.$stock['openingAsOf'] : 'as brought forward for the year' }},
                    closing as at {{ $stock['asOf'] }}.
                @else
                    Closing stock is not yet posted to the books; it is valued as at {{ $stock['asOf'] }}.
                @endif
            </div>
        @endif

        <table class="items-table">
            <thead>
                <tr>
                    <th>Particulars</th>
                    <th class="text-right" style="width: 18%;">Amount</th>
                    <th class="text-right" style="width: 18%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="3"><strong>Revenue from operations</strong></td></tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $revenue['salesGroups'], 'money' => $money])
                <tr>
                    <td>Sales</td>
                    <td></td>
                    <td class="text-right">{{ $money($revenue['sales']) }}</td>
                </tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $revenue['salesReturnGroups'], 'money' => $money])
                <tr>
                    <td>Less: Sales Return</td>
                    <td></td>
                    <td class="text-right">{{ $deduction($revenue['salesReturn']) }}</td>
                </tr>
                <tr>
                    <td><strong>Net Revenue from operations</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $money($revenue['net']) }}</strong></td>
                </tr>

                <tr><td colspan="3"><strong>Cost of goods sold / direct cost</strong></td></tr>
                <tr>
                    <td>Opening Stock</td>
                    <td></td>
                    <td class="text-right">{{ $money($cost['openingStock']['amount']) }}</td>
                </tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $cost['purchaseGroups'], 'money' => $money])
                <tr>
                    <td>Add: Purchases</td>
                    <td></td>
                    <td class="text-right">{{ $money($cost['purchases']) }}</td>
                </tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $cost['purchaseReturnGroups'], 'money' => $money])
                <tr>
                    <td>Less: Purchase Return</td>
                    <td></td>
                    <td class="text-right">{{ $deduction($cost['purchaseReturn']) }}</td>
                </tr>
                @if(count($cost['directExpenseGroups']) > 0)
                    @include('pdf.partials.income-statement-groups', ['groups' => $cost['directExpenseGroups'], 'money' => $money])
                    <tr>
                        <td>Add: Direct Expenses</td>
                        <td></td>
                        <td class="text-right">{{ $money($cost['directExpenses']) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Less: Closing Stock</td>
                    <td></td>
                    <td class="text-right">{{ $deduction($cost['closingStock']['amount']) }}</td>
                </tr>
                <tr>
                    <td><strong>Total Cost of Sales</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $money($cost['total']) }}</strong></td>
                </tr>

                <tr style="background: #f0f0f0;">
                    <td><strong>Gross Profit / (Loss)</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $signed($statement['grossProfit']) }}</strong></td>
                </tr>

                <tr><td colspan="3"><strong>Add: Other Business Income</strong></td></tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $otherIncome['groups'], 'money' => $money])
                <tr>
                    <td><strong>Total Other Business Income</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $money($otherIncome['total']) }}</strong></td>
                </tr>

                <tr><td colspan="3"><strong>Less: Expenses</strong></td></tr>
                @include('pdf.partials.income-statement-groups', ['groups' => $expenseSection['groups'], 'money' => $money])
                <tr>
                    <td><strong>Total Expenses</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $money($expenseSection['total']) }}</strong></td>
                </tr>

                <tr style="background: #f0f0f0;">
                    <td><strong>Net Profit / (Loss)</strong></td>
                    <td></td>
                    <td class="text-right"><strong>{{ $signed($statement['netProfit']) }}</strong></td>
                </tr>
            </tbody>
        </table>

        @include('pdf.partials.signatures')
    @else
        <table class="items-table">
            <thead>
                <tr>
                    <th>Income</th>
                    <th class="text-right" style="width: 20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($income as $row)
                    <tr>
                        <td>{{ $row['name'] }}{{ $row['computed'] ? ' (computed)' : '' }}</td>
                        <td class="text-right">{{ Money::of($row['amount'])->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total Income</strong></td>
                    <td class="text-right"><strong>{{ Money::of($totalIncome)->format() }}</strong></td>
                </tr>
            </tfoot>
        </table>

        <table class="items-table" style="margin-top: 14px;">
            <thead>
                <tr>
                    <th>Expenses</th>
                    <th class="text-right" style="width: 20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($expenses as $row)
                    <tr>
                        <td>{{ $row['name'] }}{{ $row['computed'] ? ' (computed)' : '' }}</td>
                        <td class="text-right">{{ Money::of($row['amount'])->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total Expenses</strong></td>
                    <td class="text-right"><strong>{{ Money::of($totalExpenses)->format() }}</strong></td>
                </tr>
            </tfoot>
        </table>

        <table class="totals-table">
            <tr class="grand-total">
                <td>Gross Profit</td>
                <td class="text-right">{{ Money::of($grossProfit)->format() }}</td>
            </tr>
            <tr class="grand-total">
                <td>Net Profit</td>
                <td class="text-right">{{ Money::of($netProfit)->format() }}</td>
            </tr>
        </table>
        <div class="clearfix"></div>

        @include('pdf.partials.signatures')
    @endif
@endsection
