<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28mm 16mm 22mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #222; line-height: 1.35; }
        header { position: fixed; top: -22mm; left: 0; right: 0; height: 18mm; }
        footer { position: fixed; bottom: -16mm; left: 0; right: 0; height: 12mm; font-size: 7.5pt; color: #777; text-align: center; border-top: 0.5pt solid #ccc; padding-top: 2mm; }
        .pagenum:after { content: counter(page); }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #666; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .center { text-align: center; }
        h1 { font-size: 16pt; margin: 0 0 1mm 0; color: #3F51B5; }
        .box { border: 0.5pt solid #ccc; border-radius: 2mm; padding: 3mm 4mm; vertical-align: top; }
        .label { font-size: 7.5pt; text-transform: uppercase; color: #888; letter-spacing: 0.3pt; margin-bottom: 1mm; }
        .lines th { background: #3F51B5; color: #fff; font-size: 8pt; font-weight: bold; padding: 2mm 1.5mm; text-align: left; }
        .lines th.right { text-align: right; }
        .lines td { padding: 2mm 1.5mm; border-bottom: 0.5pt solid #e3e3e3; vertical-align: top; }
        .lines tr.section td { background: #eef0fa; font-weight: bold; color: #3F51B5; border-bottom: none; }
        .lines tr.text td { color: #444; }
        .lines tr.optional td { color: #777; font-style: italic; }
        .badge { font-size: 7pt; border: 0.5pt solid #999; border-radius: 1mm; padding: 0 1mm; font-style: normal; }
        .totals td { padding: 1.2mm 2mm; }
        .totals .grand td { font-size: 12pt; font-weight: bold; color: #3F51B5; border-top: 1pt solid #3F51B5; }
        .pre { white-space: pre-line; }
        .description { white-space: pre-line; }
        .notice { border: 0.5pt solid #3F51B5; background: #eef0fa; border-radius: 2mm; padding: 2.5mm 4mm; }
        @stack('styles')
    </style>
</head>
<body>
<header>
    <table>
        <tr>
            <td style="width: 55%; vertical-align: middle;">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 16mm; max-width: 70mm;">
                @else
                    <strong style="font-size: 13pt;">{{ $company['name'] ?? config('app.name') }}</strong>
                @endif
            </td>
            <td class="right small muted" style="vertical-align: middle;">
                <strong style="color: #222;">{{ $company['name'] ?? '' }}</strong><br>
                {{ $company['address'] ?? '' }} {{ $company['postal_code'] ?? '' }} {{ $company['city'] ?? '' }} @if(!empty($company['province']))({{ $company['province'] }})@endif<br>
                @if(!empty($company['vat_number']))P.IVA {{ $company['vat_number'] }}@endif
                @if(!empty($company['tax_code']) && ($company['tax_code'] ?? '') !== ($company['vat_number'] ?? '')) · C.F. {{ $company['tax_code'] }}@endif
            </td>
        </tr>
    </table>
</header>

<footer>
    {{ $company['name'] ?? '' }}
    @if(!empty($company['email'])) · {{ $company['email'] }}@endif
    @if(!empty($company['phone'])) · Tel. {{ $company['phone'] }}@endif
    @if(!empty($company['pec'])) · PEC {{ $company['pec'] }}@endif
    @if(!empty($company['rea'])) · REA {{ $company['rea'] }}@endif
    @if(!empty($company['share_capital'])) · Cap. soc. {{ $company['share_capital'] }}@endif
    <br>@yield('title') · pagina <span class="pagenum"></span>
</footer>

@yield('content')
</body>
</html>
