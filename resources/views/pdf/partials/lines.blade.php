{{-- Lines table of orders and invoices. Expects $lines and the helpers. --}}
<table class="lines">
    <thead>
        <tr>
            <th style="width: 46%;">Descrizione</th>
            <th class="right" style="width: 9%;">Q.tà</th>
            <th style="width: 6%;">U.m.</th>
            <th class="right" style="width: 13%;">Prezzo</th>
            <th class="right" style="width: 7%;">Sc.</th>
            <th class="right" style="width: 6%;">IVA</th>
            <th class="right" style="width: 13%;">Importo</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            @if ($line->type->value === 'section')
                <tr class="section"><td colspan="7">{{ $line->description }}</td></tr>
            @elseif ($line->type->value === 'text')
                <tr class="text"><td colspan="7" class="description">{{ $line->description }}</td></tr>
            @else
                <tr>
                    <td>
                        @if ($line->code)<span class="muted small">{{ $line->code }}</span><br>@endif
                        <span class="description">{{ $line->description }}</span>
                    </td>
                    <td class="right">{{ $qty($line->quantity) }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="right">{{ $unitPrice($line->unit_price) }}</td>
                    <td class="right">{{ (float) $line->discount_percent > 0 ? $percent($line->discount_percent) : '' }}</td>
                    <td class="right">{{ $line->vat_nature ?: $percent($line->vat_rate) }}</td>
                    <td class="right">{{ $money($line->total) }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
