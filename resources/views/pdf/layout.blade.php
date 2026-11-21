<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 95px 36px 60px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; }
    header { position: fixed; top: -75px; left: 0; right: 0; border-bottom: 1.5px solid #333; padding-bottom: 6px; }
    footer { position: fixed; bottom: -42px; left: 0; right: 0; border-top: 1px solid #bbb; padding-top: 4px; font-size: 8px; color: #666; }
    .pn:before { content: "Page " counter(page); }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eceff1; text-align: left; padding: 4px 5px; border-bottom: 1px solid #999; font-size: 9px; }
    td { padding: 3px 5px; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
    .r { text-align: right; }
    .muted { color: #666; }
    .depot { font-size: 15px; font-weight: bold; }
    .doc { font-size: 13px; font-weight: bold; }
    h2 { font-size: 11px; margin: 16px 0 5px 0; padding-bottom: 2px; border-bottom: 1px solid #333; text-transform: uppercase; }
    .kpi td { border: 0; padding: 2px 5px; }
    .kpi .v { text-align: right; font-weight: bold; }
    .total td { border-top: 1.5px solid #333; border-bottom: 0; font-weight: bold; }
    .neg { color: #b00020; }
    .box { border: 1px solid #999; padding: 6px 8px; margin: 6px 0; }
    .nobreak { page-break-inside: avoid; }
</style>
</head>
<body>
<header>
    <table>
        <tr>
            <td style="border:0;"><span class="depot">{{ $depot }}</span></td>
            <td class="r" style="border:0;"><span class="doc">@yield('title')</span><br><span class="muted">@yield('subtitle')</span></td>
        </tr>
    </table>
</header>
<footer>
    <table>
        <tr>
            <td style="border:0;" class="muted">Édité le {{ \App\Support\Fmt::date(now()) }}</td>
            <td style="border:0;" class="r muted pn"></td>
        </tr>
    </table>
</footer>
@yield('content')
</body>
</html>
