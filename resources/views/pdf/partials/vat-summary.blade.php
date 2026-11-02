<div class="label">Riepilogo IVA</div>
<table class="small">
    @foreach ($totals['vat_summary'] as $group)
        <tr>
            <td>{{ $group['nature'] ? 'Natura ' . $group['nature'] : 'IVA ' . $percent($group['rate']) }}</td>
            <td class="right">imponibile {{ $money($group['taxable']) }}</td>
            <td class="right">imposta {{ $money($group['vat']) }}</td>
        </tr>
    @endforeach
</table>
@if ($legalReferences->isNotEmpty())
    <p class="small muted" style="margin-top: 2mm;">{{ $legalReferences->implode(' · ') }}</p>
@endif
