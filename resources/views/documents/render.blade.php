{{-- Belge gövdesi (faz 47): önizleme, yazdırma ve PDF (dompdf) aynı HTML. Satır içi CSS: dompdf harici stil okumaz.
     $template (alanlar), $values (yer tutucu değerleri), $columns (anahtar → etiket), $sub (yer tutucu değiştirici), $cancelled --}}
@php($accent = $template['accent'] ?? '#1f5f4b')
@php($live = $live ?? false)
<div class="doc" style="font-family:'DejaVu Sans',Arial,sans-serif;font-size:12px;color:#1c1c1c;max-width:760px;margin:0 auto;padding:28px 32px;background:#fff;position:relative">
    @if ($cancelled)
        <div style="position:absolute;top:40%;left:15%;right:15%;text-align:center;font-size:56px;font-weight:700;color:#c0392b;opacity:.18;transform:rotate(-18deg);letter-spacing:.2em">İPTAL</div>
    @endif
    <table style="width:100%;border-collapse:collapse;margin-bottom:18px"><tr>
        <td style="vertical-align:top;width:55%">
            <div data-slot="logo">
                @if (! empty($template['logo_url']) || $live)<img src="{{ $template['logo_url'] ?? '' }}" alt="{{ $values['business_name'] ?? '' }}" style="max-height:56px;max-width:220px;{{ empty($template['logo_url']) ? 'display:none' : '' }}">@endif
                <div data-text style="font-size:20px;font-weight:700;color:{{ $accent }};{{ empty($template['logo_url']) ? '' : 'display:none' }}">{{ $values['business_name'] ?? '' }}</div>
            </div>
            @if (! empty($template['show_business']) || $live)
                <div data-slot="business" style="margin-top:6px;font-size:11px;color:#444;line-height:1.5;{{ empty($template['show_business']) ? 'display:none' : '' }}">
                    @if (($values['business_legal_name'] ?? '') !== '' && ($values['business_legal_name'] ?? '') !== ($values['business_name'] ?? ''))<div>{{ $values['business_legal_name'] }}</div>@endif
                    @if (($values['business_address'] ?? '') !== '')<div>{{ $values['business_address'] }}</div>@endif
                    <div>{{ trim(($values['business_phone'] ?? '').' '.($values['business_email'] ?? '')) }}</div>
                </div>
            @endif
        </td>
        <td style="vertical-align:top;text-align:right">
            <div data-slot="heading" style="font-size:18px;font-weight:700;letter-spacing:.06em;color:{{ $accent }}">{{ $sub($template['heading'] ?? '') }}</div>
            <div data-slot="subheading" style="font-size:11px;color:#444;margin-top:4px">{{ $sub($template['subheading'] ?? '') }}</div>
        </td>
    </tr></table>

    <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:11.5px"><tr>
        <td style="vertical-align:top;width:50%;padding:8px 10px;border:1px solid #ddd">
            <div style="font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.08em">Müşteri</div>
            <div style="font-weight:700;margin-top:2px">{{ $values['customer_name'] ?? '' }}</div>
            @if (($values['customer_tax_number'] ?? '') !== '')<div>VKN/TCKN: {{ $values['customer_tax_number'] }}</div>@endif
            @if (($values['customer_email'] ?? '') !== '')<div>{{ $values['customer_email'] }}</div>@endif
        </td>
        <td style="vertical-align:top;padding:8px 10px;border:1px solid #ddd;border-left:0">
            <div style="font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.08em">Belge</div>
            <div><b>{{ $values['document_number'] ?? '' }}</b> · {{ $values['date'] ?? '' }}</div>
            <div>Fatura: {{ $values['invoice_number'] ?? '' }} @if (($values['due_date'] ?? '') !== '')· Vade: {{ $values['due_date'] }}@endif</div>
            @if (isset($values['days_overdue']))<div>Gecikme: {{ $values['days_overdue'] }} gün</div>@endif
        </td>
    </tr></table>

    @if (($template['intro'] ?? '') !== '' || $live)
        <p data-slot="intro" style="margin:0 0 12px;line-height:1.55;{{ ($template['intro'] ?? '') === '' ? 'display:none' : '' }}">{{ $sub($template['intro'] ?? '') }}</p>
    @endif

    @if ($columns !== [] || $live)
        <table data-slot="table" style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:11.5px;{{ $columns === [] ? 'display:none' : '' }}">
            <thead><tr>
                @foreach ($columns as $key => $label)<th style="text-align:{{ in_array($key, ['amount', 'paid_amount', 'remaining_amount', 'invoice_total'], true) ? 'right' : 'left' }};padding:7px 8px;background:{{ $accent }};color:#fff;font-weight:600">{{ $label }}</th>@endforeach
            </tr></thead>
            <tbody><tr>
                @foreach ($columns as $key => $label)<td style="text-align:{{ in_array($key, ['amount', 'paid_amount', 'remaining_amount', 'invoice_total'], true) ? 'right' : 'left' }};padding:7px 8px;border-bottom:1px solid #ddd">{{ $values[$key] ?? '' }}</td>@endforeach
            </tr></tbody>
        </table>
    @endif

    @if (($template['body'] ?? '') !== '' || $live)
        <div data-slot="body" style="line-height:1.6;margin-bottom:18px;{{ ($template['body'] ?? '') === '' ? 'display:none' : '' }}">
            @foreach (preg_split('/\r?\n/', (string) ($template['body'] ?? '')) ?: [] as $line)
                <div>{{ $sub($line) }}</div>
            @endforeach
        </div>
    @endif

    @if (($values['note'] ?? '') !== '')
        <p style="margin:0 0 18px;font-size:11px;color:#444"><b>Not:</b> {{ $values['note'] }}</p>
    @endif

    @if (($template['signature'] ?? '') !== '' || ($template['stamp'] ?? '') !== '' || $live)
        <table style="width:100%;border-collapse:collapse;margin-top:28px;font-size:11px"><tr>
            <td style="width:50%;vertical-align:bottom;padding-right:20px">
                <div data-slot="signature" style="border-top:1px solid #555;padding-top:6px;width:70%;{{ ($template['signature'] ?? '') === '' ? 'display:none' : '' }}"><span data-text>{{ $sub($template['signature'] ?? '') }}</span><br><span style="color:#777">{{ $values['issuer_name'] ?? '' }}</span></div>
            </td>
            <td style="width:50%;vertical-align:bottom;text-align:right">
                <div data-slot="stamp" style="display:{{ ($template['stamp'] ?? '') === '' ? 'none' : 'inline-block' }};border:1px dashed #999;border-radius:8px;padding:22px 26px;color:#777">{{ $sub($template['stamp'] ?? '') }}</div>
            </td>
        </tr></table>
    @endif

    @if (($template['footer'] ?? '') !== '' || $live)
        <div data-slot="footer" style="margin-top:26px;padding-top:8px;border-top:1px solid #ddd;font-size:10px;color:#777;line-height:1.5;{{ ($template['footer'] ?? '') === '' ? 'display:none' : '' }}">
            @foreach (preg_split('/\r?\n/', (string) ($template['footer'] ?? '')) ?: [] as $line)<div>{{ $sub($line) }}</div>@endforeach
        </div>
    @endif
</div>
