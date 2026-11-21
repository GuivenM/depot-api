@extends('pdf.layout')
@section('title', 'État du stock')
@section('subtitle', 'au '.$date)
@section('content')
@php $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit); @endphp

<table class="kpi">
    <tr><td>Valeur du stock au coût d'achat</td><td class="v">{{ $m($total_value) }}</td></tr>
    @if ($unknown_cost > 0)
    <tr><td class="muted">{{ $unknown_cost }} produit(s) sans coût d'achat renseigné : valeur non comptée</td><td></td></tr>
    @endif
</table>

<h2>Produits</h2>
<table>
    <thead>
        <tr><th>Produit</th><th class="r">Casiers</th><th class="r">Bouteilles seules</th><th class="r">Total bouteilles</th><th class="r">Seuil</th><th>État</th><th class="r">Coût / casier</th><th class="r">Valeur</th></tr>
    </thead>
    <tbody>
        @foreach ($rows as $r)
        <tr>
            <td>{{ $r['name'] }}</td>
            <td class="r">{{ $r['crates'] }}</td>
            <td class="r">{{ $r['loose'] }}</td>
            <td class="r">{{ $r['bottles'] }}</td>
            <td class="r">{{ $r['threshold'] }}</td>
            <td class="{{ $r['status'] === 'Rupture' ? 'neg' : '' }}">{{ $r['status'] }}</td>
            <td class="r">{{ $r['cost'] === null ? '—' : $m($r['cost'], false) }}</td>
            <td class="r">{{ $r['value'] === null ? '—' : $m($r['value'], false) }}</td>
        </tr>
        @endforeach
        <tr class="total"><td colspan="7">Total</td><td class="r">{{ $m($total_value, false) }}</td></tr>
    </tbody>
</table>
<p class="muted">Montants en FCFA. Seuil exprimé en bouteilles.</p>

@if (count($empties))
<h2>Casiers vides au dépôt</h2>
<table class="nobreak">
    @foreach ($empties as $e)
    <tr><td>{{ $e['name'] }}</td><td class="r">{{ $e['quantity'] }}</td></tr>
    @endforeach
</table>
@endif
@endsection
