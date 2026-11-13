<html>
<head><meta charset="UTF-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #1d4ed8; color: #fff; padding: 8px; text-align: left; }
    td { padding: 8px; border-bottom: 1px solid #e8ecf5; }
    .r { text-align: right; }
</style></head>
<body>
    <table>
        <tr>
            <td style="border:0;"><img src="{{ public_path('goerone_logo.png') }}" height="40"></td>
            <td class="r" style="border:0;">
                <div style="font-size:22px;font-weight:bold;color:#1d4ed8;">INVOICE</div>
                <div>No: {{ $invoice->invoiceNo }}</div>
                <div>Date: {{ \Carbon\Carbon::parse($invoice->InvoiceDate)->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <p style="margin-top:24px;">
        <strong>Billed to</strong><br>
        {{ $customer->name ?? '' }}<br>
        {{ $customer->email ?? '' }}<br>
        {{ $customer->mobile ?? '' }}
    </p>

    <table style="margin-top:16px;">
        <tr>
            <th>Item</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">GST</th><th class="r">Amount</th>
        </tr>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->ItemName }}</td>
                <td class="r">{{ $item->Quantity }}</td>
                <td class="r">{{ number_format($item->Rate, 2) }}</td>
                <td class="r">{{ number_format($item->TaxAmount, 2) }}</td>
                <td class="r">{{ number_format($item->Amount, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <table style="margin-top:16px; width:45%; margin-left:55%;">
        <tr><td>Sub total</td><td class="r">{{ number_format($invoice->SubTotal, 2) }}</td></tr>
        <tr><td>GST (18%)</td><td class="r">{{ number_format($invoice->TotalTaxAmount, 2) }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="r"><strong>INR {{ number_format($invoice->TotalAmount, 2) }}</strong></td></tr>
    </table>

    <p style="margin-top:40px;color:#888;font-size:10px;">This is a computer generated invoice.</p>
</body>
</html>