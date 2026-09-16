@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Income Statement')

@section('content')
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
@endsection
