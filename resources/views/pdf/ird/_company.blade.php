{{-- Seller block and copy stamp, the top of every IRD invoice format.
     $panLabel is the legacy per-format wording for the seller's tax number. --}}
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
            @if($company->address)
                <p>{{ $company->address }}</p>
            @endif
            <p><strong>{{ $panLabel }} :</strong> {{ $company->pan_vat_number }}</p>
            @if($company->email)
                <p>{{ $company->email }}</p>
            @endif
        </td>
        {{-- Only the first print is the original; every reprint has to say so
             on the face of the document (C9). --}}
        <td class="copy-note" style="width: 25%;">
            {{ $printCopyNumber > 1 ? 'Copy of Original - '.($printCopyNumber - 1) : 'Original' }}
        </td>
    </tr>
</table>
