{{-- Belgeler (faz 47): şablonlar + düzenlenmiş belgeler --}}
<div class="card">
    <div class="card__head"><h3>Belge ayarları / şablonlar</h3><span class="sub">Logo, başlık, metin, tablo, imza/kaşe, alt bilgi ve dinamik alanlar</span></div>
    <div class="rows">
        @foreach ($kinds as $key => $label)
            <a href="{{ route('panel.collections.templates.edit', $key) }}" class="row">
                <div class="main-t"><b>{{ $label }}</b><span>Şablonu düzenle · canlı önizleme</span></div>
                <span class="rt"><span class="btn btn--quiet">Düzenle</span></span>
            </a>
        @endforeach
    </div>
</div>
<div class="card">
    <div class="card__head"><h3>Düzenlenen belgeler</h3><span class="sub">{{ $documentsList->count() }} kayıt · son 30</span></div>
    @if ($documentsList->isEmpty())
        <div class="empty-state" style="border:0">Henüz belge yok. Makbuz tahsilat satırından, geciken ödeme belgesi açık fatura satırındaki "Belge oluştur" ile.</div>
    @else
        <div class="tw"><table class="t">
            <thead><tr><th>Belge no</th><th>Tür</th><th>Müşteri</th><th>Fatura</th><th>Tarih</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                @foreach ($documentsList as $d)
                    <tr style="{{ $d->isCancelled() ? 'opacity:.6' : '' }}">
                        <td class="mono"><a href="{{ route('panel.collections.documents.show', $d) }}">{{ $d->number }}</a></td>
                        <td><span class="tag">{{ $d->kindLabel() }}</span></td>
                        <td>{{ $d->company?->legal_name ?? '—' }}</td>
                        <td class="mono small">{{ $d->invoice?->number ?? '—' }}</td>
                        <td class="mono small">{{ $d->created_at->format('d.m.Y H:i') }}<span class="mini" style="display:block">{{ $d->creator?->name ?? 'Sistem' }}</span></td>
                        <td>@if ($d->isCancelled())<span class="pill n">İptal</span>@else<span class="pill g">Geçerli</span>@endif</td>
                        <td class="num"><span style="display:inline-flex;gap:4px"><a href="{{ route('panel.collections.documents.show', $d) }}" class="btn btn--quiet">Önizle</a><a href="{{ route('panel.collections.documents.pdf', $d) }}" class="btn btn--quiet">PDF</a><a href="{{ route('panel.collections.documents.print', $d) }}" class="btn btn--quiet" target="_blank" rel="noopener">Yazdır</a></span></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>
