@php $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit); @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu {{ $number }}</title>
<style>
    @page { margin: 28px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eceff1; text-align: left; padding: 4px 5px; border-bottom: 1px solid #999; font-size: 9px; }
    td { padding: 3px 5px; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
    .r { text-align: right; }
    .muted { color: #666; }
    .depot { font-size: 16px; font-weight: bold; }
    .head td { border: 0; padding: 1px 0; }
    .sum td { border: 0; padding: 2px 5px; }
    .big td { border-top: 1.5px solid #333; font-weight: bold; font-size: 12px; }
    .void { border: 2px solid #b00020; color: #b00020; font-weight: bold; text-align: center; padding: 6px; margin: 8px 0; }
    .box { border: 1px solid #999; padding: 5px 8px; margin-top: 8px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td><span class="depot">{{ $depot }}</span></td>
        <td class="r"><strong>Reçu {{ $number }}</strong></td>
    </tr>
    <tr>
        <td class="muted">{{ $sold_at }}</td>
        <td class="r muted">Vendeur : {{ $seller }}</td>
    </tr>
    @if ($customer)
    <tr>
        <td colspan="2">Client : <strong>{{ $customer['name'] }}</strong>@if ($customer['phone']) — {{ $customer['phone'] }}@endif</td>
    </tr>
    @endif
</table>

@if ($voided)
    <div class="void">
        VENTE ANNULÉE
        @if ($void_reason)
            — {{ $void_reason }}
        @endif
    </div>
@endif

<table style="margin-top:10px;">
    <thead>
        <tr><th>Désignation</th><th class="r">Qté</th><th class="r">Prix unitaire</th><th class="r">Total</th></tr>
    </thead>
    <tbody>
        @foreach ($lines as $l)
        <tr>
            <td>{{ $l['name'] }}</td>
            <td class="r">{{ $l['quantity'] }} {{ $l['unit'] }}{{ $l['quantity'] > 1 ? 's' : '' }}</td>
            <td class="r">{{ $m($l['unit_price']) }}</td>
            <td class="r">{{ $m($l['total']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="sum" style="margin-top:6px;">
    <tr class="big"><td>TOTAL</td><td class="r">{{ $m($total) }}</td></tr>
    <tr><td class="muted">Mode de paiement</td><td class="r">{{ $method }}</td></tr>
    @if ($is_credit)
    <tr><td class="muted">Payé à la vente</td><td class="r">{{ $m($paid) }}</td></tr>
    <tr><td><strong>Reste à payer</strong></td><td class="r"><strong>{{ $m($remaining) }}</strong></td></tr>
    @endif
</table>

@if (count($containers))
<div class="box">
    <strong>Casiers à rendre</strong><br>
    @foreach ($containers as $c)
        {{ $c['quantity'] }} × {{ $c['name'] }}<br>
    @endforeach
</div>
@endif

<p class="muted" style="text-align:center; margin-top:16px;">Merci de votre confiance.</p>
</body>
</html>
