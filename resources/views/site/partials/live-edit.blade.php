{{-- Canlı düzenleme (faz 59) — YALNIZ yetkili oturumda basılır (SiteLayoutComposer::liveEdit; ziyaretçiye hiçbir şey gitmez).
     Düğme oturum bayrağını form POST ile açar/kapar; açıkken görsel hedefleri (data-le) çerçevelenir, tıklanınca modal:
     kütüphaneden seç / yükle / sürükle-bırak → sayfada anında değişir → Kaydet (tek form POST, JS'ten HTTP yok) → veritabanı.
     Geri al / İptal önceki görsele döner; kaydedilmeyen değişiklik yenilemede kalmaz. --}}
@php($on = ! empty($liveEdit['on']))
@php($currentPath = '/'.trim(request()->getPathInfo(), '/'))
<div class="le-bar" data-le-bar>
    @if (session('live_status'))<span class="le-flash le-flash--ok">{{ session('live_status') }}</span>@endif
    @if (session('live_error'))<span class="le-flash le-flash--err" role="alert">{{ session('live_error') }}</span>@endif
    <form method="POST" action="{{ route('panel.live.toggle') }}">
        @csrf
        <input type="hidden" name="on" value="{{ $on ? 0 : 1 }}">
        <input type="hidden" name="return" value="{{ $currentPath }}">
        <button type="submit" class="le-toggle {{ $on ? 'is-on' : '' }}" title="{{ $on ? 'Düzenleme modunu kapat' : 'Görselleri yerinde düzenle' }}">✎ Düzenleme Modu{{ $on ? ': AÇIK' : '' }}</button>
    </form>
    @if ($on && ! empty($liveEdit['can']['website']))
        {{-- Global bileşenler (faz 61a): header/footer ayar ekranına gider; değişiklik tüm sitede uygulanır. --}}
        <a href="{{ route('panel.settings.chrome.header', ['return' => $currentPath]) }}" class="le-panel-link" title="Header ayarları — tüm sitede uygulanır">Header</a>
        <a href="{{ route('panel.settings.chrome.footer', ['return' => $currentPath]) }}" class="le-panel-link" title="Footer ayarları — tüm sitede uygulanır">Footer</a>
    @endif
    <a href="{{ route('panel.dashboard') }}" class="le-panel-link">Panel</a>
</div>

@if ($on)
<div class="le-modal" data-le-modal hidden data-le-area-header="{{ route('panel.settings.chrome.header', ['return' => $currentPath]) }}" data-le-area-footer="{{ route('panel.settings.chrome.footer', ['return' => $currentPath]) }}">
    <div class="le-modal__box" role="dialog" aria-modal="true" aria-labelledby="le-title">
        <form method="POST" data-le-form enctype="multipart/form-data" class="le-modal__form"
              data-action-website="{{ route('panel.live.website') }}"
              data-action-section="{{ route('panel.live.section') }}"
              data-action-content="{{ url('/panel/canli/gorsel/icerik') }}"
              data-action-service="{{ url('/panel/canli/gorsel/hizmet') }}"
              data-action-location="{{ url('/panel/canli/gorsel/lokasyon') }}">
            @csrf
            <input type="hidden" name="action" value="replace" data-le-action>
            <input type="hidden" name="id" value="" data-le-id>
            <input type="hidden" name="field" value="" data-le-field>
            <input type="hidden" name="index" value="" data-le-index>
            <input type="hidden" name="media_id" value="" data-le-media-id>
            <input type="hidden" name="return" value="{{ $currentPath }}">

            <div class="le-modal__head">
                <div>
                    <p class="le-eyebrow">Görseli Değiştir</p>
                    <h2 id="le-title" class="le-h" data-le-title>Görsel</h2>
                    <p class="le-hint" data-le-scope></p>
                </div>
                <button type="button" class="le-x" data-le-close aria-label="Kapat">✕</button>
            </div>

            <div class="le-modal__body">
                <div class="le-preview">
                    <img src="" alt="" data-le-preview>
                    <div class="le-preview__actions">
                        <button type="button" class="le-btn le-btn--ghost" data-le-undo disabled>↶ Geri al</button>
                        <button type="button" class="le-btn le-btn--danger" data-le-remove>Kaldır</button>
                    </div>
                </div>

                <div class="le-lib">
                    <div class="le-lib__tools">
                        <input type="search" class="le-input" placeholder="Kütüphanede ara (ad, alt metin)" data-le-search>
                        <label class="le-btn le-btn--ghost" style="cursor:pointer">Yükle<input type="file" name="file" accept="image/jpeg,image/png,image/webp" hidden data-le-file></label>
                    </div>
                    <div class="le-drop" data-le-drop>Bilgisayarınızdan görseli buraya bırakın</div>
                    <div class="le-grid" data-le-grid>
                        @foreach ($liveEdit['library'] as $m)
                            <button type="button" class="le-tile" data-le-tile data-id="{{ $m['id'] }}" data-url="{{ $m['url'] }}" data-alt="{{ $m['alt'] }}" data-name="{{ $m['name'] }}" title="{{ $m['alt'] ?: $m['name'] }}">
                                <img src="{{ $m['thumb'] }}" alt="{{ $m['alt'] }}" loading="lazy">
                            </button>
                        @endforeach
                        @if ($liveEdit['library'] === [])<p class="le-hint">Kütüphane boş; yükleyin ya da bırakın.</p>@endif
                    </div>
                    <p class="le-hint">Kütüphane yönetimi (silme, toplu alt metin): <a href="{{ route('panel.content.media.index') }}" target="_blank" rel="noopener">Medya kütüphanesi ↗</a></p>
                </div>
            </div>

            <div class="le-modal__meta">
                <label class="le-field"><span>Alt metin (SEO)</span><input type="text" class="le-input" name="alt" maxlength="190" data-le-alt placeholder="Görseli tarif eden kısa metin"></label>
                <label class="le-field"><span>Açıklama (isteğe bağlı)</span><input type="text" class="le-input" name="caption" maxlength="300" data-le-caption></label>
                <p class="le-hint" data-le-global hidden>Bu görsel site geneli bir ayardır; değişiklik tüm sayfalara yansır.</p>
            </div>

            <div class="le-modal__foot">
                <button type="button" class="le-btn le-btn--ghost" data-le-cancel>İptal</button>
                <button type="submit" class="le-btn le-btn--brand" data-le-save disabled>Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script src="{{ asset_v('js/live-edit.js') }}" defer></script>
@endif
<link rel="stylesheet" href="{{ asset_v('css/live-edit.css') }}">
