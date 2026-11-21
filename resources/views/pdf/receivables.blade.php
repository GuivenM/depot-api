@extends('pdf.layout')
@section('title', 'Créances clients')
@section('subtitle', 'au '.$date)
@section('content')
@php $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit); @endphp

<table class="kpi">
    <tr><td>Total dû par les clients ({{ count($rows) }} débiteurs)</td><td class="v">{{ $m($total) }}</td></tr>
    <tr><td>Clients au-delà de leur limite de crédit</td><td class="v">{{ $over_limit }}</td></tr>
</table>

<h2>Détail, du plus gros solde au plus petit</h2>
<table>
    <thead>
        <tr><th>Client</th><th>Téléphone</th><th class="r">Solde dû</th><th class="r">Limite</th><th>Dernier paiement</th><th class="r">Casiers dus</th></tr>
    </thead>
    <tbody>
        @forelse ($rows as $r)
        <tr>
            <td>{{ $r['name'] }}<br><span class="muted">{{ $r['type'] }}</span></td>
            <td>{{ $r['phone'] }}</td>
            <td class="r {{ $r['over_limit'] ? 'neg' : '' }}"><strong>{{ $m($r['balance'], false) }}</strong></td>
            <td class="r">{{ $m($r['limit'], false) }}</td>
            <td>{{ $r['last_payment'] ?? 'jamais' }}</td>
            <td class="r">{{ $r['crates'] > 0 ? $r['crates'] : '' }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="muted">Aucun client ne doit d'argent.</td></tr>
        @endforelse
        @if (count($rows))
        <tr class="total"><td colspan="2">Total</td><td class="r">{{ $m($total, false) }}</td><td colspan="3"></td></tr>
        @endif
    </tbody>
</table>
<p class="muted">Montants en FCFA. En rouge : solde supérieur à la limite de crédit du client.</p>
@endsection
