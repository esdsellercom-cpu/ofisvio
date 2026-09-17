{{-- Blok kataloğu (faz 50): hazır bileşenler + kayıtlı bloklar. Kütüphane sayfası ($mode='library': kullanım, işlemler)
     ve görsel editörün "+ Blok ekle" penceresi ($mode='picker': data-add-type ile ekle) aynı listeyi kullanır. --}}
@php($mode = $mode ?? 'library')
@php($editor = fn (array $q) => route('panel.content.builder.index', ['website' => $website->id] + $q))

<p class="eyebrow" style="margin:0 0 8px">Kayıtlı bloklar &amp; şablonlar</p>
@if ($presets->isEmpty())
    <div class="empty-state" style="margin-bottom:16px">Kayıtlı blok yok. Editörde bir bölümü seçip <b>Blok olarak kaydet</b> deyin ya da burada <b>+ Yeni blok şablonu</b> oluşturun.</div>
@else
    <div class="grid-auto" style="--min:260px;--gap:10px;margin-bottom:18px">
        @foreach ($presets as $p)
            @php($use = $usage['presets'][$p->id] ?? ['draft' => [], 'published' => 0])
            <div class="card" style="padding:12px 14px;display:flex;flex-direction:column;gap:8px" @if ($mode === 'picker') draggable="true" data-add-type="preset:{{ $p->id }}" @endif>
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                    <div style="min-width:0"><b style="display:block;overflow:hidden;text-overflow:ellipsis">{{ $p->name }}</b><span class="small muted">{{ $library[$p->type]['label'] ?? $p->type }} · {{ $categories[$p->category] ?? $p->category }}</span></div>
                    @if ($p->is_global)<span class="pill g flat" title="Global: değişince bağlı tüm kullanımlar güncellenir">🌐 global</span>@else<span class="pill n flat" title="Normal: eklenince kopyalanır, yalnız o sayfayı etkiler">normal</span>@endif
                </div>
                @if ($mode === 'library')
                    <div class="small muted">Kullanım: @if ($use['draft'] !== [])<a href="{{ $editor(['secim' => $use['draft'][0]]) }}">Ana sayfa (taslak, {{ count($use['draft']) }})</a>@else taslakta yok @endif · yayında {{ $use['published'] }}</div>
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <a href="{{ $use['draft'] !== [] ? $editor(['secim' => $use['draft'][0]]) : $editor(['ekle' => 'preset:'.$p->id]) }}" class="btn btn--brand btn--pill">Tasarımda düzenle</a>
                        <a href="{{ route('panel.content.blocks.preset.preview', $p->id) }}" class="btn btn--ghost btn--pill">Önizle</a>
                        @can('content.edit')
                            <button type="button" class="btn btn--ghost btn--pill" data-modal-open="#modal-preset-edit" data-action="{{ route('panel.content.blocks.preset.update', $p->id) }}" data-fill='@json(['name' => $p->name, 'category' => $p->category, 'is_global' => $p->is_global ? '1' : ''])' data-title="Bloğu düzenle: {{ $p->name }}">Ad / kategori</button>
                            <form method="POST" action="{{ route('panel.content.blocks.preset.duplicate', $p->id) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Kopyala</button></form>
                            <form method="POST" action="{{ route('panel.content.blocks.preset.destroy', $p->id) }}" onsubmit="return confirm('Blok silinsin mi? Bağlı bölümler kendi kopyalarıyla kalır.')">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Sil</button></form>
                        @endcan
                    </div>
                @else
                    <button type="button" class="btn btn--brand btn--pill" data-add-type="preset:{{ $p->id }}" data-modal-add>Sayfaya ekle</button>
                @endif
            </div>
        @endforeach
    </div>
@endif

<p class="eyebrow" style="margin:0 0 8px">Hazır bileşenler</p>
@foreach ($groups as $gk => $gl)
    <p class="small" style="margin:10px 0 6px;font-weight:600">{{ $gl }}</p>
    <div class="grid-auto" style="--min:230px;--gap:8px">
        @foreach ($library as $type => $def)
            @if ($def['group'] === $gk)
                @php($u = $usage['types'][$type] ?? ['section' => null, 'pages' => []])
                <div class="card" style="padding:10px 12px;display:flex;flex-direction:column;gap:6px" @if ($mode === 'picker') draggable="true" data-add-type="{{ $type }}" @endif>
                    <div style="display:flex;gap:8px;align-items:center"><span class="ve-chip__icon" aria-hidden="true">{{ $def['icon'] }}</span><b>{{ $def['label'] }}</b>@if ($def['unique'] ?? false)<span class="small muted" title="Sayfada bir kez">·1</span>@endif</div>
                    <span class="small muted">{{ $def['description'] }} <span title="Veri kaynağı">({{ $def['source'] }})</span></span>
                    @if ($mode === 'library')
                        <span class="small muted">Kullanım: @if ($u['section'])<a href="{{ $editor(['secim' => $u['section']]) }}">Ana sayfa</a>@else ana sayfada yok @endif @if ($u['pages'] !== [])· sayfalar: @foreach ($u['pages'] as $pg)<a href="{{ route('panel.content.show', $pg['id']) }}">{{ $pg['title'] }}</a>@if (! $loop->last), @endif @endforeach @endif</span>
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <a href="{{ $u['section'] ? $editor(['secim' => $u['section']]) : $editor(['ekle' => $type]) }}" class="btn btn--ghost btn--pill">{{ $u['section'] ? 'Tasarımda düzenle' : 'Tasarıma ekle' }}</a>
                            @can('content.edit')<button type="button" class="btn btn--quiet btn--pill" data-modal-open="#modal-preset-new" data-fill='@json(['type' => $type, 'name' => $def['label']])'>Şablon oluştur</button>@endcan
                        </div>
                    @else
                        <button type="button" class="btn btn--ghost btn--pill" data-add-type="{{ $type }}" data-modal-add @if (($def['unique'] ?? false) && ($u['section'] ?? null)) disabled title="Sayfada zaten var" @endif>Sayfaya ekle</button>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
@endforeach
