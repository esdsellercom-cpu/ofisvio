{{-- Finansal hareketler (faz 51): tarih · tür · açıklama · belge · oluşturan · tutar (+ tahsilat / − fatura, ek harcama) --}}
@if (count($rows) === 0)
    <div class="empty-state" style="border:0">Hareket yok.</div>
@else
    <div class="tw"><table class="t"><thead><tr><th>Tarih</th><th>Tür</th><th>Açıklama</th><th>Belge</th><th>Oluşturan</th><th class="num">Tutar</th></tr></thead><tbody>
        @foreach ($rows as $r)
            <tr @if ($r['cancelled']) style="opacity:.55;text-decoration:line-through" @endif>
                <td class="small mono">{{ $r['date']->format('d.m.Y') }}</td>
                <td><span class="pill {{ $r['type'] === 'payment' ? 'g' : ($r['type'] === 'charge' ? 'w' : 'n') }} flat">{{ $r['label'] }}</span></td>
                <td class="small">{{ $r['description'] }}</td>
                <td class="small">@if ($r['document'])<a href="{{ $r['document']['url'] }}" class="mono">{{ $r['document']['label'] }}</a>@else — @endif</td>
                <td class="small">{{ $r['actor'] }}</td>
                <td class="num"><b style="color:{{ $r['amount'] > 0 ? 'var(--ok, #1a7f4b)' : ($r['amount'] < 0 ? 'var(--danger)' : 'inherit') }}">{{ $r['amount'] > 0 ? '+' : ($r['amount'] < 0 ? '−' : '') }}{{ money(abs($r['amount'])) }}</b></td>
            </tr>
        @endforeach
    </tbody></table></div>
@endif
