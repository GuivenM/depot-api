@extends('pdf.layout')
@section('title', "Rapport d'activité")
@section('subtitle', $period)
@section('content')
@php
    $m = fn ($v, $unit = true) => \App\Support\Fmt::money($v, $unit);
    $s = $overview['sales'];
    $snap = $overview['snapshot'];
@endphp

<h2>Synthèse</h2>
<table class="kpi">
    <tr><td>Ventes validées ({{ $s['count'] }})</td><td class="v">{{ $m($s['revenue']) }}</td></tr>
    @foreach ($methods as $key => $label)
    <tr><td class="muted">&nbsp;&nbsp;dont {{ $label }} ({{ $s['by_method'][$key]['count'] }})</td><td class="v" style="font-weight:normal;">{{ $m($s['by_method'][$key]['amount']) }}</td></tr>
    @endforeach
    <tr><td>Ventes annulées ({{ $s['voided']['count'] }})</td><td class="v">{{ $m($s['voided']['amount']) }}</td></tr>
    <tr><td>Marge estimée (sur les produits dont le coût est connu)</td><td class="v">{{ $m($overview['margin']['estimated_margin']) }}</td></tr>
    @if ($overview['margin']['uncosted_revenue'] > 0)
    <tr><td class="muted">&nbsp;&nbsp;chiffre d'affaires sans coût connu (hors marge)</td><td class="v" style="font-weight:normal;">{{ $m($overview['margin']['uncosted_revenue']) }}</td></tr>
    @endif
    <tr><td>Dépenses de caisse</td><td class="v">{{ $m($overview['expenses']['total']) }}</td></tr>
    <tr><td>Achats reçus ({{ $overview['purchases']['count'] }})</td><td class="v">{{ $m($overview['purchases']['total']) }}</td></tr>
</table>

<h2>Encaissements de la période</h2>
<table class="kpi">
    <tr><td>Paiements de clients — espèces</td><td class="v">{{ $m($overview['collections']['customer_payments']['cash']) }}</td></tr>
    <tr><td>Paiements de clients — Mobile Money</td><td class="v">{{ $m($overview['collections']['customer_payments']['momo']) }}</td></tr>
    <tr><td>Paiements aux fournisseurs — espèces / Mobile Money / banque</td>
        <td class="v">{{ $m($overview['collections']['supplier_payments']['cash'], false) }} / {{ $m($overview['collections']['supplier_payments']['momo'], false) }} / {{ $m($overview['collections']['supplier_payments']['bank']) }}</td></tr>
</table>

<h2>Journée par journée</h2>
<table>
    <thead>
        <tr><th>Date</th><th class="r">Ventes</th><th class="r">Chiffre d'affaires</th><th class="r">Espèces</th><th class="r">Mobile Money</th><th class="r">Crédit</th><th class="r">Dépenses</th></tr>
    </thead>
    <tbody>
        @forelse ($days as $d)
        <tr>
            <td>{{ \App\Support\Fmt::day($d['date']) }}</td>
            <td class="r">{{ $d['sales_count'] }}</td>
            <td class="r">{{ $m($d['revenue'], false) }}</td>
            <td class="r">{{ $m($d['cash'], false) }}</td>
            <td class="r">{{ $m($d['momo'], false) }}</td>
            <td class="r">{{ $m($d['credit'], false) }}</td>
            <td class="r">{{ $m($d['expenses'], false) }}</td>
        </tr>
        @empty
        <tr><td colspan="7" class="muted">Aucune vente sur la période.</td></tr>
        @endforelse
    </tbody>
</table>
<p class="muted">Montants en FCFA. Les jours sans vente ni dépense ne sont pas listés.</p>

<h2>Meilleurs produits</h2>
<table class="nobreak">
    <thead><tr><th>Produit</th><th class="r">Casiers</th><th class="r">Bouteilles</th><th class="r">Chiffre d'affaires</th></tr></thead>
    <tbody>
        @forelse ($top as $t)
        <tr><td>{{ $t['name'] }}</td><td class="r">{{ $t['crates_sold'] }}</td><td class="r">{{ $t['bottles_sold'] }}</td><td class="r">{{ $m($t['revenue']) }}</td></tr>
        @empty
        <tr><td colspan="4" class="muted">Aucune vente sur la période.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Caisses clôturées</h2>
<table class="nobreak">
    <thead><tr><th>Clôture</th><th>Caissier</th><th class="r">Fond</th><th class="r">Théorique</th><th class="r">Compté</th><th class="r">Écart</th></tr></thead>
    <tbody>
        @forelse ($cash['sessions'] as $c)
        <tr>
            <td>{{ \App\Support\Fmt::date($c['closed_at']) }}</td>
            <td>{{ $c['user'] }}</td>
            <td class="r">{{ $m($c['opening_amount'], false) }}</td>
            <td class="r">{{ $m($c['expected_cash'], false) }}</td>
            <td class="r">{{ $m($c['counted_cash'], false) }}</td>
            <td class="r {{ $c['difference'] < 0 ? 'neg' : '' }}">{{ \App\Support\Fmt::signed($c['difference']) }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="muted">Aucune caisse clôturée sur la période.</td></tr>
        @endforelse
    </tbody>
</table>
@if (count($cash['by_user']))
<p class="muted">
    Écarts par caissier :
    @foreach ($cash['by_user'] as $u)
        {{ $u['user'] }} ({{ $u['sessions_with_gap'] }}/{{ $u['sessions'] }} caisses avec écart, total {{ \App\Support\Fmt::signed($u['total_difference']) }}){{ ! $loop->last ? ' ;' : '' }}
    @endforeach
</p>
@endif

<h2>Situation à ce jour</h2>
<table class="kpi nobreak">
    <tr><td>Créances clients ({{ $snap['receivables']['debtors'] }} débiteurs, {{ $snap['receivables']['over_limit'] }} au-delà de leur limite)</td><td class="v">{{ $m($snap['receivables']['total']) }}</td></tr>
    <tr><td>Dettes fournisseurs</td><td class="v">{{ $m($snap['payables']['total']) }}</td></tr>
    <tr><td>Stock valorisé au coût d'achat</td><td class="v">{{ $m($snap['stock']['value_at_cost']) }}</td></tr>
    <tr><td>Casiers vides au dépôt / dus par les clients / dus aux fournisseurs</td>
        <td class="v">{{ $snap['containers']['empty_stock'] }} / {{ $snap['containers']['owed_by_customers'] }} / {{ $snap['containers']['owed_to_suppliers'] }}</td></tr>
</table>

@if ($alerts['count'] > 0)
<h2>Produits à réapprovisionner</h2>
<table class="nobreak">
    <thead><tr><th>Produit</th><th class="r">En stock</th><th>État</th></tr></thead>
    <tbody>
        @foreach ($alerts['products'] as $a)
        <tr>
            <td>{{ $a['name'] }}</td>
            <td class="r">{{ $a['stock_crates'] }} casiers{{ $a['stock_loose_bottles'] > 0 ? ' + '.$a['stock_loose_bottles'].' bout.' : '' }}</td>
            <td class="{{ $a['status'] === 'rupture' ? 'neg' : '' }}">{{ ucfirst($a['status']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif
@endsection
