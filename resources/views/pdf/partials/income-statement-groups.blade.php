{{-- One income statement section as Group -> Sub-group -> Ledger rows, fully
     expanded (a printout shows everything). Included by pdf/income-statement.blade.php.

     Variables:
         $groups  list of nodes { name, total, rows[], subgroups[] { name, total, rows[] } }
         $money   closure formatting an amount string

     Ledger and total rows put their figure in the Amount column; the section
     total that follows the include goes in the Total column. A group or
     sub-group with a single ledger and nothing below it skips its own
     "<name> Total" row, because that row would repeat the ledger's figure. --}}
@foreach($groups as $group)
    <tr>
        <td colspan="3" style="padding-left: 12px; font-weight: bold; background: #f5f5f5;">{{ $group['name'] }}</td>
    </tr>
    @foreach($group['rows'] as $row)
        <tr>
            <td style="padding-left: 24px;">{{ $row['name'] }}</td>
            <td class="text-right">{{ $money($row['amount']) }}</td>
            <td></td>
        </tr>
    @endforeach
    @foreach($group['subgroups'] as $subgroup)
        <tr>
            <td colspan="3" style="padding-left: 24px; font-weight: bold;">{{ $subgroup['name'] }}</td>
        </tr>
        @foreach($subgroup['rows'] as $row)
            <tr>
                <td style="padding-left: 36px;">{{ $row['name'] }}</td>
                <td class="text-right">{{ $money($row['amount']) }}</td>
                <td></td>
            </tr>
        @endforeach
        @if(count($subgroup['rows']) > 1)
            <tr>
                <td style="padding-left: 24px; font-weight: bold;">{{ $subgroup['name'] }} Total</td>
                <td class="text-right"><strong>{{ $money($subgroup['total']) }}</strong></td>
                <td></td>
            </tr>
        @endif
    @endforeach
    @if(count($group['rows']) + count($group['subgroups']) > 1)
        <tr>
            <td style="padding-left: 12px; font-weight: bold;">{{ $group['name'] }} Total</td>
            <td class="text-right"><strong>{{ $money($group['total']) }}</strong></td>
            <td></td>
        </tr>
    @endif
@endforeach
