@php
    $s = $invoice->seller;
    $b = $invoice->buyer;
    $money = fn (int $cents) => '€ '.number_format($cents / 100, 2, ',', '.');
@endphp
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>Factuur {{ $invoice->number }}</title>
<style>
    @page { margin: 36px 44px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1d1b18; }
    h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.3px; }
    .muted { color: #6f6a62; }
    .brand { font-size: 15px; font-weight: bold; color: #a87718; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .lines { margin-top: 28px; }
    .lines th { text-align: left; border-bottom: 1.5px solid #1d1b18; padding: 6px 4px; font-size: 10px; }
    .lines td { padding: 8px 4px; border-bottom: 1px solid #e3ded5; vertical-align: top; }
    .right { text-align: right; }
    .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
    .totals td { padding: 4px; }
    .totals .grand td { border-top: 1.5px solid #1d1b18; font-weight: bold; font-size: 12px; padding-top: 7px; }
    .paid { margin-top: 26px; padding: 9px 12px; background: #f4efe4; border-left: 3px solid #a87718; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8.5px; color: #6f6a62; text-align: center; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%; vertical-align:top">
            <div class="brand">ReviewPilot</div>
            <div style="margin-top:6px">
                <strong>{{ $s['legal_name'] }}</strong><br>
                @if($s['address']){{ $s['address'] }}<br>@endif
                @if($s['postal_code'] || $s['city']){{ trim($s['postal_code'].' '.$s['city']) }}<br>@endif
                {{ $s['country'] }}<br>
                {{ $s['email'] }}
            </div>
        </td>
        <td style="vertical-align:top" class="right">
            <h1>Factuur</h1>
            <span class="muted">Invoice</span>
        </td>
    </tr>
</table>

<table style="margin-top:28px">
    <tr>
        <td style="width:55%; vertical-align:top">
            <div class="muted">Factuur aan / Bill to</div>
            <strong>{{ $b['name'] }}</strong><br>
            @if($b['address']){{ $b['address'] }}<br>@endif
            @if($b['postal_code'] || $b['city']){{ trim($b['postal_code'].' '.$b['city']) }}<br>@endif
            {{ $b['country'] }}
            @if($b['vat_number'])<br>Btw-nr: {{ $b['vat_number'] }}@endif
        </td>
        <td style="vertical-align:top">
            <table class="meta">
                <tr><td class="muted">Factuurnummer</td><td class="right"><strong>{{ $invoice->number }}</strong></td></tr>
                <tr><td class="muted">Factuurdatum</td><td class="right">{{ $invoice->issued_on->format('d-m-Y') }}</td></tr>
                @if($s['kvk'])<tr><td class="muted">KvK</td><td class="right">{{ $s['kvk'] }}</td></tr>@endif
                @if($s['vat_number'])<tr><td class="muted">Btw-nummer</td><td class="right">{{ $s['vat_number'] }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr><th>Omschrijving</th><th class="right" style="width:12%">Aantal</th><th class="right" style="width:22%">Bedrag excl. btw</th></tr>
    </thead>
    <tbody>
    @foreach($invoice->lines as $line)
        <tr>
            <td>{{ $line['description'] }}</td>
            <td class="right">{{ $line['quantity'] }}</td>
            <td class="right">{{ $money($line['net_cents']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotaal excl. btw</td><td class="right">{{ $money($invoice->net_cents) }}</td></tr>
    <tr><td>Btw {{ rtrim(rtrim(number_format($invoice->vat_rate * 100, 2, ',', ''), '0'), ',') }}%</td><td class="right">{{ $money($invoice->vat_cents) }}</td></tr>
    <tr class="grand"><td>Totaal</td><td class="right">{{ $money($invoice->gross_cents) }}</td></tr>
</table>

<div class="paid">
    Dit bedrag is op {{ $invoice->issued_on->format('d-m-Y') }} voldaan via Mollie. U hoeft niets meer te betalen.<br>
    <span class="muted">This invoice has been paid. No action is needed.</span>
</div>

<div class="footer">
    {{ $s['legal_name'] }}@if($s['kvk']) · KvK {{ $s['kvk'] }}@endif @if($s['vat_number'])· Btw {{ $s['vat_number'] }}@endif @if($s['iban'])· IBAN {{ $s['iban'] }}@endif · {{ $s['email'] }}
</div>
</body>
</html>
