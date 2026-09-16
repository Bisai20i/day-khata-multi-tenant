@extends('pdf.layout')

@php
    use App\Support\Money\Money;
@endphp

@section('title', 'Balance Sheet')

@section('content')
    @if($balanceWarning ?? null)
        <div class="legal-note">{{ $balanceWarning }}</div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th>Account</th>
                <th class="text-right" style="width: 18%;">Debit</th>
                <th class="text-right" style="width: 18%;">Credit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($heads as $head)
                <tr><td colspan="3"><strong>{{ $head['name'] }}</strong></td></tr>
                @foreach($head['groups'] as $group)
                    @foreach($group['accounts'] as $account)
                        <tr>
                            <td>{{ $account['code'] ? "{$account['code']} - {$account['name']}" : $account['name'] }}</td>
                            <td class="text-right">{{ Money::of($account['debit'])->format() }}</td>
                            <td class="text-right">{{ Money::of($account['credit'])->format() }}</td>
                        </tr>
                    @endforeach
                    @foreach($group['subgroups'] as $subgroup)
                        @foreach($subgroup['accounts'] as $account)
                            <tr>
                                <td>{{ $account['code'] ? "{$account['code']} - {$account['name']}" : $account['name'] }}</td>
                                <td class="text-right">{{ Money::of($account['debit'])->format() }}</td>
                                <td class="text-right">{{ Money::of($account['credit'])->format() }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalAssets)->format() }}</strong></td>
                <td class="text-right"><strong>{{ Money::of($totalLiabilitiesAndCapital)->format() }}</strong></td>
            </tr>
        </tfoot>
    </table>
@endsection
