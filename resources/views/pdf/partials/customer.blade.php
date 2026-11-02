{{-- Customer box. Expects $customer (snapshot) and optional $contact. --}}
<td class="box" style="width: 50%;">
    <div class="label">Spett.le</div>
    <strong>{{ $customer['name'] ?? '' }}</strong><br>
    @if(!empty($customer['address'])){{ $customer['address'] }}<br>@endif
    @if(!empty($customer['city_line'])){{ $customer['city_line'] }}@if(!empty($customer['country']) && $customer['country'] !== 'IT') – {{ $customer['country'] }}@endif<br>@endif
    @if(!empty($customer['vat_number']))P.IVA {{ $customer['vat_number'] }}<br>@endif
    @if(!empty($customer['tax_code']) && $customer['tax_code'] !== ($customer['vat_number'] ?? ''))C.F. {{ $customer['tax_code'] }}<br>@endif
    @if(!empty($showSdi))
        @if(!empty($customer['sdi_code']) && $customer['sdi_code'] !== '0000000')<span class="small">Cod. destinatario {{ $customer['sdi_code'] }}</span><br>@elseif(!empty($customer['pec']))<span class="small">PEC {{ $customer['pec'] }}</span><br>@endif
    @endif
    @if (!empty($contact))
        <span class="muted">Alla c.a. {{ $contact->full_name }}</span>
    @endif
</td>
