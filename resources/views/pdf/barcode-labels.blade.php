<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Barcode Labels</title>
<style>
    @page {
        margin: 10px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #1a1a1a;
    }

    .label {
        display: inline-block;
        width: 190px;
        height: 110px;
        margin: 4px;
        padding: 6px;
        border: 1px dashed #999;
        text-align: center;
        vertical-align: top;
        overflow: hidden;
    }

    .label-name {
        font-size: 9px;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .label-price {
        font-size: 9px;
        margin-top: 2px;
    }

    .label-barcode-img {
        margin-top: 6px;
        height: 40px;
    }

    .label-barcode-text {
        font-size: 9px;
        letter-spacing: 1px;
        margin-top: 2px;
    }

    .label-no-barcode {
        margin-top: 24px;
        font-size: 8px;
        color: #888;
        font-style: italic;
    }
</style>
</head>
<body>
    @foreach ($labels as $label)
        <div class="label">
            <div class="label-name">{{ $label['name'] }}</div>

            @if ($label['price'] !== null)
                <div class="label-price">Rs. {{ number_format((float) $label['price'], 2) }}</div>
            @endif

            @if ($label['barcodeImage'])
                <img class="label-barcode-img" src="data:image/png;base64,{{ $label['barcodeImage'] }}" alt="{{ $label['barcode'] }}">
                <div class="label-barcode-text">{{ $label['barcode'] }}</div>
            @else
                <div class="label-no-barcode">No barcode assigned</div>
            @endif
        </div>
    @endforeach
</body>
</html>
