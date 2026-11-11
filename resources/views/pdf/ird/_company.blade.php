{{-- Seller block and copy stamp, the top of every IRD invoice format.
     $panLabel is the legacy per-format wording for the seller's tax number.
     $branchPrintDetails (plan 12, BR21) is the company's address, email and PAN,
     or the document's branch's own where it has them; the IRD format prints no phone. --}}
@php
    $logoPath = $company->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
        : null;
    $printCopyNumber = max(1, (int) ($copyNumber ?? 1));
@endphp
<table class="header section">
    <tr>
        <td class="company-info">
            @if($logoPath)
                <img src="{{ $logoPath }}" alt="{{ $company->company_name }} logo" class="company-logo">
            @endif
            <h1 class="company-name">{{ $company->company_name }}</h1>
            @if($branchPrintDetails['address'])
                <p>{{ $branchPrintDetails['address'] }}</p>
            @endif
            {{-- Multi-branch companies only (plan 12, BR12): the controller
                 passes a null branchName otherwise (and the estimate none),
                 so single-branch bills are unchanged. --}}
            @if(! empty($branchName))
                <p><strong>{{ $branchLabel ?? 'Branch' }} :</strong> {{ $branchName }}</p>
            @endif
            <p><strong>{{ $panLabel }} :</strong> {{ $branchPrintDetails['pan'] }}</p>
            @if($branchPrintDetails['email'])
                <p>{{ $branchPrintDetails['email'] }}</p>
            @endif
        </td>
        {{-- Only the first print is the original; every reprint has to say so
             on the face of the document (C9). --}}
        <td class="copy-note" style="width: 25%;">
            @if($isEstimate)
                Estimate<br>Not a Tax Invoice
            @else
                {{ $printCopyNumber > 1 ? 'Copy of Original - '.($printCopyNumber - 1) : 'Original' }}
            @endif
        </td>
    </tr>
</table>
