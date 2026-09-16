@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Trial Balance')

@section('content')
    <table class="items-table">
        <thead>
            <tr>
                <th>Account</th>
                <th class="text-right" style="width: 11%;">Opening Dr</th>
                <th class="text-right" style="width: 11%;">Opening Cr</th>
                <th class="text-right" style="width: 11%;">Period Dr</th>
                <th class="text-right" style="width: 11%;">Period Cr</th>
                <th class="text-right" style="width: 11%;">Closing Dr</th>
                <th class="text-right" style="width: 11%;">Closing Cr</th>
            </tr>
        </thead>
        <tbody>
            @foreach($heads as $head)
                @foreach($head['groups'] as $group)
                    @foreach($group['accounts'] as $account)
                        <tr>
                            <td>{{ $account['code'] ? "{$account['code']} - {$account['name']}" : $account['name'] }}</td>
                            <td class="text-right">{{ Money::of($account['openingDebit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['openingCredit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['periodDebit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['periodCredit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['closingDebit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['closingCredit'])->format() }}</td>
                        </tr>
                    @endforeach
                    @foreach($group['subgroups'] as $subgroup)
                        @foreach($subgroup['accounts'] as $account)
                            <tr>
                                <td>{{ $account['code'] ? "{$account['code']} - {$account['name']}" : $account['name'] }}</td>
                                <td class="text-right">{{ Money::of($account['openingDebit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['openingCredit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['periodDebit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['periodCredit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['closingDebit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['closingCredit'])->format() }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalOpeningDebit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalOpeningCredit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalPeriodDebit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalPeriodCredit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalDebit)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalCredit)->format() }}</strong></td>
            </tr>
        </tfoot>
    </table>
@endsection
