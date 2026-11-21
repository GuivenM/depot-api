@extends('pdf.layout')
@section('title', $open ? 'Caisse en cours' : 'Clôture de caisse')
@section('subtitle', $number)
@section('content')
@php $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit); $sum = $summary; @endphp

<table class="kpi">
    <tr><td>Titulaire de la caisse</td><td class="v">{{ $owner }}</td></tr>
    <tr><td>Ouverte le</td><td class="v">{{ $opened_at }}</td></tr>
    @unless ($open)
    <tr><td>Clôturée le</td><td class="v">{{ $closed_at }}@if ($closed_by && $closed_by !== $owner) par {{ $closed_by }}@endif</td></tr>
    @endunless
    <tr><td>Ventes validées / annulées</td><td class="v">{{ $sales_count }} / {{ $voided_count }}</td></tr>
</table>

<h2>Ventes</h2>
<table class="kpi">
    <tr><td>Espèces</td><td class="v">{{ $m($sum['cash_sales']) }}</td></tr>
    <tr><td>Mobile Money</td><td class="v">{{ $m($sum['momo_sales']) }}</td></tr>
    <tr><td>À crédit</td><td class="v">{{ $m($sum['credit_sales']) }}</td></tr>
    <tr><td>Paiements de clients reçus — espèces / Mobile Money</td><td class="v">{{ $m($sum['customer_payments_cash'], false) }} / {{ $m($sum['customer_payments_momo']) }}</td></tr>
</table>

<h2>Calcul de la caisse en espèces</h2>
<table class="kpi">
    <tr><td>Fond de caisse à l'ouverture</td><td class="v">{{ $m($sum['opening_amount']) }}</td></tr>
    <tr><td>+ Ventes en espèces</td><td class="v">{{ $m($sum['cash_sales']) }}</td></tr>
    <tr><td>+ Paiements de clients en espèces</td><td class="v">{{ $m($sum['customer_payments_cash']) }}</td></tr>
    <tr><td>− Dépenses</td><td class="v">{{ $m($sum['expenses']) }}</td></tr>
    <tr><td>− Paiements fournisseurs en espèces</td><td class="v">{{ $m($sum['supplier_payments_cash']) }}</td></tr>
    <tr class="total"><td>Caisse théorique</td><td class="v">{{ $m($sum['expected_cash']) }}</td></tr>
    @unless ($open)
    <tr><td>Caisse comptée</td><td class="v">{{ $m($counted) }}</td></tr>
    <tr class="total"><td>Écart</td><td class="v {{ $difference < 0 ? 'neg' : '' }}">{{ \App\Support\Fmt::signed($difference) }}</td></tr>
    @endunless
</table>
@if ($note)
    <p class="muted">Note : {{ $note }}</p>
@endif

@if (count($expenses))
<h2>Dépenses</h2>
<table class="nobreak">
    <thead><tr><th>Heure</th><th>Catégorie</th><th>Note</th><th class="r">Montant</th></tr></thead>
    <tbody>
        @foreach ($expenses as $e)
        <tr><td>{{ $e['time'] }}</td><td>{{ $e['category'] }}</td><td class="muted">{{ $e['note'] }}</td><td class="r">{{ $m($e['amount']) }}</td></tr>
        @endforeach
    </tbody>
</table>
@endif

@unless ($open)
<table style="margin-top:46px;">
    <tr>
        <td style="border:0; width:50%;" class="muted">Signature du caissier</td>
        <td style="border:0;" class="muted">Signature du gérant</td>
    </tr>
</table>
@endunless
@endsection
