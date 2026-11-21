@extends('pdf.layout')
@section('title', 'Relevé de compte')
@section('subtitle', $period)
@section('content')
@php $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit); @endphp

<div class="box">
    <strong style="font-size:12px;">{{ $customer['name'] }}</strong>
    @if ($customer['phone']) — {{ $customer['phone'] }} @endif
    <span class="muted">({{ $customer['type'] }})</span><br>
    <span class="muted">Limite de crédit :</span> {{ $m($customer['credit_limit']) }}
</div>

<table class="kpi" style="margin-top:8px;">
    <tr><td>Solde au début de la période</td><td class="v">{{ $m($opening) }}</td></tr>
    <tr><td>Achats à crédit et ajustements en plus</td><td class="v">{{ $m($charged) }}</td></tr>
    <tr><td>Paiements, annulations et ajustements en moins</td><td class="v">{{ $m($paid) }}</td></tr>
    <tr class="total"><td>Solde à la fin de la période</td><td class="v">{{ $m($closing) }}</td></tr>
</table>
@if ($balance_now !== $closing)
    <p class="muted">Solde actuel du client (aujourd'hui) : <strong>{{ $m($balance_now) }}</strong></p>
@endif

<h2>Détail des mouvements</h2>
<table>
    <thead>
        <tr><th>Date</th><th>Opération</th><th>Note</th><th class="r">Montant</th><th class="r">Solde après</th></tr>
    </thead>
    <tbody>
        @forelse ($rows as $r)
        <tr>
            <td>{{ $r['date'] }}</td>
            <td>{{ $r['label'] }}</td>
            <td class="muted">{{ $r['note'] }}</td>
            <td class="r {{ $r['amount'] < 0 ? 'neg' : '' }}">{{ \App\Support\Fmt::signed($r['amount']) }}</td>
            <td class="r">{{ $m($r['balance_after']) }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="muted">Aucun mouvement sur la période.</td></tr>
        @endforelse
    </tbody>
</table>
@if ($truncated)
    <p class="muted">Seuls les 2 000 premiers mouvements sont affichés : réduisez la période.</p>
@endif

@if (count($holdings))
<h2>Casiers à rendre</h2>
<table>
    @foreach ($holdings as $h)
    <tr><td>{{ $h['name'] }}</td><td class="r">{{ $h['quantity'] }}</td></tr>
    @endforeach
</table>
@endif
@endsection
