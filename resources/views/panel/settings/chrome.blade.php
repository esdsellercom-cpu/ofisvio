@extends('layouts.panel')

@section('title', ($area === 'header' ? 'Header' : 'Footer').' ayarları')

@php($c = $config)
@php($isHeader = $area === 'header')
@php($base = route('panel.settings.chrome.'.$area))

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.settings.index') }}">Ayarlar</a> · {{ $website->name }}</p>
            <h1 class="h2">{{ $isHeader ? 'Header ayarları' : 'Footer ayarları' }}</h1>
            <p>Global bileşen: <strong>bu değişiklik tüm sitede uygulanacaktır.</strong> "Taslak olarak önizle" ziyaretçiye görünmez (görsel editör önizlemesiyle aynı); "Yayınla" önce mevcut yapılandırmayı sürüm olarak saklar, sonra canlıya alır — geri alma aşağıda. Telefon, e-posta, adres, WhatsApp, saatler ve marka adı <a href="{{ route('panel.websites.index') }}">Websiteler</a>'de; menü metinleri (otomatik menü) görsel editörde.</p>
        </div>
        <div class="panel-head__actions">
            @if ($websites->count() > 1)@foreach ($websites as $w)<a href="{{ $base }}?website={{ $w->id }}" class="btn btn--ghost btn--pill" @if ($w->id === $website->id) aria-current="page" @endif>{{ $w->name }}</a>@endforeach @endif
            <a href="{{ route('panel.settings.chrome.'.($isHeader ? 'footer' : 'header')) }}" class="btn btn--ghost btn--pill">{{ $isHeader ? 'Footer ayarları' : 'Header ayarları' }}</a>
            <a href="{{ route('panel.content.builder.index') }}" class="btn btn--ghost btn--pill">Görsel editör</a>
            @if ($hasDraft)<span class="badge badge--warn">Yayınlanmamış taslak var</span>@endif
        </div>
    </div>

    @if ($hasDraft)
        <div class="note w" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <span>Bu formda taslak değerler görünüyor (ziyaretçi canlı sürümü görüyor).</span>
            <a href="{{ $previewUrl }}" class="btn btn--ghost btn--pill" target="_blank" rel="noopener">Önizlemeyi aç ↗</a>
            <form method="POST" action="{{ route('panel.settings.chrome.discard', $area) }}">@csrf<input type="hidden" name="website" value="{{ $website->id }}"><button type="submit" class="btn btn--ghost btn--pill">Taslağı at</button></form>
        </div>
    @endif

    <form method="POST" action="{{ route('panel.settings.chrome.publish', $area) }}" class="stack" style="gap:18px" data-chrome-form>
        @csrf
        <input type="hidden" name="website" value="{{ $website->id }}">
        @if ($returnTo)<input type="hidden" name="return" value="{{ $returnTo }}">@endif

        @if ($isHeader)
            <div class="panel stack" style="gap:12px">
                <p class="eyebrow" style="margin:0">Logo</p>
                <div class="grid-auto" style="--min:220px;--gap:12px">
                    <label class="field"><span class="label">Logo (medya kütüphanesi; boş = marka adı)</span>
                        <select class="control" name="c[logo_media_id]"><option value="">— marka adı yazısı —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((int) $c['logo_media_id'] === $m->id)>{{ $m->original_name }} ({{ $m->width }}×{{ $m->height }})</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Mobil logo (isteğe bağlı)</span>
                        <select class="control" name="c[logo_mobile_media_id]"><option value="">— aynı logo —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((int) $c['logo_mobile_media_id'] === $m->id)>{{ $m->original_name }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Favicon / uygulama simgesi (kare PNG, en az 180 px)</span>
                        <select class="control" name="c[favicon_media_id]"><option value="">— simge yok —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((int) ($c['favicon_media_id'] ?? 0) === $m->id)>{{ $m->original_name }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Logo yüksekliği (px)</span><input class="control mono" type="number" name="c[logo_height]" value="{{ $c['logo_height'] }}" min="20" max="80"></label>
                </div>
                <p class="small muted" style="margin:0">Favicon: <code>public/favicon.ico</code> deploy ile; medya kütüphanesinden yüklenmez (tarayıcı önbelleği).</p>
            </div>

            <div class="panel stack" style="gap:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                    <p class="eyebrow" style="margin:0">Menü — sürükleyerek sıralayın; boş bırakılırsa yayınlanmış bölüm çapalarından otomatik menü</p>
                    <button type="button" class="btn btn--ghost btn--pill" data-add="menu">+ Menü öğesi</button>
                </div>
                <div class="stack" style="gap:8px" data-list="menu" data-name="c[menu]" data-template="tpl-menu">
                    @foreach ($c['menu'] as $i => $item)
                        <div class="panel" style="padding:10px;background:var(--surface-2)" data-row>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <span data-handle title="Sürükle" style="cursor:grab;user-select:none">⋮⋮</span>
                                <input class="control" type="text" data-field="[label]" value="{{ $item['label'] }}" placeholder="Başlık" maxlength="60" style="flex:1 1 160px">
                                <input class="control mono" type="text" data-field="[href]" value="{{ $item['href'] }}" placeholder="/cozumler ya da #cozumler ya da https://…" maxlength="300" style="flex:2 1 220px">
                                <label class="checkbox-row small"><input type="checkbox" data-field="[mega]" value="1" @checked($item['mega'])><span>mega menü</span></label>
                                <label class="checkbox-row small"><input type="checkbox" data-field="[new_tab]" value="1" @checked($item['new_tab'])><span>yeni sekme</span></label>
                                <button type="button" class="btn btn--ghost btn--pill" data-add-child data-add="children">+ Alt öğe</button>
                                <button type="button" class="btn btn--ghost btn--pill" data-remove style="color:var(--danger)">Sil</button>
                            </div>
                            <div class="stack" style="gap:6px;margin:8px 0 0 28px" data-list data-child="[children]" data-child-list data-template="tpl-child">
                                @foreach ($item['children'] as $child)
                                    <div style="display:flex;gap:6px;flex-wrap:wrap" data-row>
                                        <span data-handle style="cursor:grab;user-select:none">⋮</span>
                                        <input class="control" type="text" data-field="[label]" value="{{ $child['label'] }}" placeholder="Alt başlık" maxlength="60" style="flex:1 1 140px">
                                        <input class="control mono" type="text" data-field="[href]" value="{{ $child['href'] }}" placeholder="/cozum/sanal-ofis" maxlength="300" style="flex:2 1 200px">
                                        <input class="control" type="text" data-field="[description]" value="{{ $child['description'] }}" placeholder="Kısa açıklama (mega)" maxlength="120" style="flex:2 1 200px">
                                        <button type="button" class="btn btn--ghost btn--pill" data-remove>×</button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="panel grid-auto" style="--min:220px;--gap:12px">
                <label class="field"><span class="label">CTA metni (boş = görsel editördeki "Teklif Al")</span><input class="control" type="text" name="c[cta][label]" value="{{ $c['cta']['label'] }}" maxlength="40"></label>
                <label class="field"><span class="label">CTA bağlantısı</span><input class="control mono" type="text" name="c[cta][href]" value="{{ $c['cta']['href'] }}" maxlength="300"></label>
                <label class="field"><span class="label">CTA stili</span><select class="control" name="c[cta][style]"><option value="brand" @selected($c['cta']['style'] === 'brand')>Dolu (marka)</option><option value="ghost" @selected($c['cta']['style'] === 'ghost')>Çerçeveli</option></select></label>
                <label class="field"><span class="label">Aktif menü görünümü</span><select class="control" name="c[active_style]"><option value="underline" @selected($c['active_style'] === 'underline')>Alt çizgi</option><option value="pill" @selected($c['active_style'] === 'pill')>Hap arka plan</option><option value="bold" @selected($c['active_style'] === 'bold')>Kalın</option></select></label>
                <label class="field"><span class="label">Header yüksekliği (px)</span><input class="control mono" type="number" name="c[height]" value="{{ $c['height'] }}" min="56" max="120"></label>
                <div class="stack" style="gap:6px">
                    <label class="checkbox-row"><input type="checkbox" name="c[sticky]" value="1" @checked($c['sticky'])><span>Sticky (kaydırınca üstte kalır)</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[transparent]" value="1" @checked($c['transparent'])><span>Şeffaf (yalnız ana sayfada, hero üstünde)</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_login]" value="1" @checked($c['show_login'])><span>Giriş / Panel bağlantısı</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_phone]" value="1" @checked($c['show_phone'])><span>Telefonu göster</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_email]" value="1" @checked($c['show_email'])><span>E-postayı göster</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[mobile_show_locations]" value="1" @checked($c['mobile_show_locations'])><span>Mobil menüde lokasyonlar</span></label>
                </div>
                <div class="grid-auto" style="--min:110px;--gap:8px;grid-column:1/-1">
                    @foreach (['bg' => 'Arka plan', 'text' => 'Metin', 'hover' => 'Hover', 'active' => 'Aktif'] as $key => $label)
                        <label class="field"><span class="label">{{ $label }} rengi</span><input class="control mono" type="text" name="c[colors][{{ $key }}]" value="{{ $c['colors'][$key] }}" placeholder="#1f5b45 (boş = tema)" pattern="#?[0-9a-fA-F]{6}|"></label>
                    @endforeach
                </div>
            </div>
        @else
            <div class="panel grid-auto" style="--min:240px;--gap:12px">
                <label class="field"><span class="label">Logo (boş = marka adı)</span>
                    <select class="control" name="c[logo_media_id]"><option value="">— marka adı yazısı —</option>@foreach ($mediaOptions as $m)<option value="{{ $m->id }}" @selected((int) $c['logo_media_id'] === $m->id)>{{ $m->original_name }}</option>@endforeach</select>
                </label>
                <label class="field" style="grid-column:1/-1"><span class="label">Açıklama (boş = site sloganı)</span><textarea class="control" name="c[description]" maxlength="300" style="min-height:64px">{{ $c['description'] }}</textarea></label>
                <div class="stack" style="gap:6px">
                    <label class="checkbox-row"><input type="checkbox" name="c[show_contact]" value="1" @checked($c['show_contact'])><span>Telefon / e-posta / WhatsApp</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_address]" value="1" @checked($c['show_address'])><span>Adres</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_hours]" value="1" @checked($c['show_hours'])><span>Çalışma saatleri</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="c[show_location]" value="1" @checked($c['show_location'])><span>Lokasyon / bölge sütunu</span></label>
                </div>
                <label class="field"><span class="label">CTA metni</span><input class="control" type="text" name="c[cta][label]" value="{{ $c['cta']['label'] }}" maxlength="40"></label>
                <label class="field"><span class="label">CTA bağlantısı</span><input class="control mono" type="text" name="c[cta][href]" value="{{ $c['cta']['href'] }}" maxlength="300"></label>
            </div>

            <div class="panel stack" style="gap:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                    <p class="eyebrow" style="margin:0">Menü kolonları — sürükleyerek sıralayın; boşsa görsel editördeki "Footer sütunları" bloğu</p>
                    <button type="button" class="btn btn--ghost btn--pill" data-add="columns">+ Kolon ekle</button>
                </div>
                <div class="grid-auto" style="--min:260px;--gap:10px" data-list="columns" data-name="c[columns]" data-template="tpl-column">
                    @foreach ($c['columns'] as $col)
                        <div class="panel" style="padding:10px;background:var(--surface-2)" data-row>
                            <div style="display:flex;gap:6px;align-items:center"><span data-handle style="cursor:grab;user-select:none">⋮⋮</span><input class="control" type="text" data-field="[title]" value="{{ $col['title'] }}" placeholder="Kolon başlığı" maxlength="60" style="flex:1"><button type="button" class="btn btn--ghost btn--pill" data-remove style="color:var(--danger)">Sil</button></div>
                            <div class="stack" style="gap:6px;margin-top:8px" data-list data-child="[items]" data-child-list data-template="tpl-item">
                                @foreach ($col['items'] as $item)
                                    <div style="display:flex;gap:6px" data-row><span data-handle style="cursor:grab;user-select:none">⋮</span><input class="control" type="text" data-field="[label]" value="{{ $item['label'] }}" placeholder="Bağlantı metni" maxlength="80" style="flex:1"><input class="control mono" type="text" data-field="[href]" value="{{ $item['href'] }}" placeholder="/blog" maxlength="300" style="flex:1"><button type="button" class="btn btn--ghost btn--pill" data-remove>×</button></div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn--ghost btn--pill" data-add-child data-add="items" style="margin-top:6px">+ Bağlantı</button>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="panel grid-auto" style="--min:220px;--gap:12px">
                <label class="checkbox-row" style="grid-column:1/-1"><input type="checkbox" name="c[newsletter][enabled]" value="1" @checked($c['newsletter']['enabled'])><span><strong>Bülten formu</strong> — kayıtlar CRM taleplerine "Bülten" türüyle düşer (KVKK rıza kanıtıyla).</span></label>
                <label class="field"><span class="label">Bülten başlığı</span><input class="control" type="text" name="c[newsletter][title]" value="{{ $c['newsletter']['title'] }}" maxlength="60"></label>
                <label class="field"><span class="label">Bülten metni</span><input class="control" type="text" name="c[newsletter][text]" value="{{ $c['newsletter']['text'] }}" maxlength="200"></label>
                @foreach (['kvkk' => 'KVKK / aydınlatma', 'privacy' => 'Gizlilik politikası', 'cookies' => 'Çerez politikası', 'terms' => 'Kullanım koşulları'] as $key => $label)
                    <label class="field"><span class="label">{{ $label }} sayfası</span>
                        <select class="control" name="c[legal][{{ $key }}]"><option value="">— seçilmedi —</option>@foreach ($pages as $p)<option value="{{ $p->id }}" @selected((int) $c['legal'][$key] === $p->id)>{{ $p->title }}</option>@endforeach</select>
                    </label>
                @endforeach
                <p class="small muted" style="grid-column:1/-1;margin:0">Hiç seçim yoksa yayındaki tüm sayfalar alt şeritte listelenir (eski davranış). Yasal metinler CMS'de sayfa olarak yönetilir.</p>
                <label class="field"><span class="label">Copyright (boş = © yıl tüzel ad)</span><input class="control" type="text" name="c[copyright]" value="{{ $c['copyright'] }}" maxlength="120"></label>
                <label class="field"><span class="label">Alt bilgi</span><input class="control" type="text" name="c[bottom_text]" value="{{ $c['bottom_text'] }}" maxlength="200"></label>
                <label class="field" style="grid-column:1/-1"><span class="label">Çerez rıza bandı metni (GA4/GTM tanımlıysa gösterilir; boş = varsayılan)</span><input class="control" type="text" name="c[cookie_notice][text]" value="{{ $c['cookie_notice']['text'] ?? '' }}" maxlength="300"></label>
                <label class="field"><span class="label">Kabul düğmesi</span><input class="control" type="text" name="c[cookie_notice][accept]" value="{{ $c['cookie_notice']['accept'] ?? '' }}" maxlength="40" placeholder="Kabul et"></label>
                <label class="field"><span class="label">Ret düğmesi</span><input class="control" type="text" name="c[cookie_notice][decline]" value="{{ $c['cookie_notice']['decline'] ?? '' }}" maxlength="40" placeholder="Yalnız zorunlu"></label>
            </div>
        @endif

        <div class="panel stack" style="gap:8px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <p class="eyebrow" style="margin:0">Sosyal medya (https adres)</p>
                <button type="button" class="btn btn--ghost btn--pill" data-add="social">+ Bağlantı</button>
            </div>
            <div class="stack" style="gap:6px" data-list="social" data-name="c[social]" data-template="tpl-social">
                @foreach ($c['social'] as $row)
                    <div style="display:flex;gap:6px;flex-wrap:wrap" data-row><span data-handle style="cursor:grab;user-select:none">⋮</span>
                        <select class="control" data-field="[network]" style="flex:0 1 160px">@foreach ($social as $key => $label)<option value="{{ $key }}" @selected($row['network'] === $key)>{{ $label }}</option>@endforeach</select>
                        <input class="control mono" type="url" data-field="[url]" value="{{ $row['url'] }}" placeholder="https://instagram.com/ofisvio" maxlength="300" style="flex:1 1 260px">
                        <button type="button" class="btn btn--ghost btn--pill" data-remove>×</button>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <label class="field" style="flex:1 1 220px"><span class="label">Sürüm notu (isteğe bağlı)</span><input class="control" type="text" name="note" maxlength="200" placeholder="örn. Mega menü açıldı"></label>
            <button type="submit" class="btn btn--ghost" formaction="{{ route('panel.settings.chrome.preview', $area) }}">Taslak olarak önizle</button>
            @if ($canPublish)<button type="submit" class="btn btn--brand" data-confirm="Bu değişiklik tüm sitede uygulanacaktır. Yayınlansın mı?">Yayınla (tüm sitede)</button>@else<span class="small muted">Yayın için website.manage gerekir; taslak önizleyebilirsiniz.</span>@endif
        </div>
    </form>

    <div class="panel" style="margin-top:18px">
        <p class="eyebrow">Sürüm geçmişi — geri alma</p>
        @if ($versions->isEmpty())<p class="body-muted small" style="margin:0">Henüz sürüm yok; ilk yayınla birlikte oluşur.</p>@else
            <table class="data"><thead><tr><th>#</th><th>Tarih</th><th>Kim</th><th>Not</th><th></th></tr></thead><tbody>
                @foreach ($versions as $v)
                    <tr><td class="mono">{{ $v->number }}</td><td class="small">{{ $v->created_at->format('d.m.Y H:i') }}</td><td class="small">{{ $v->author?->name ?? '—' }}</td><td class="small">{{ $v->note ?? '—' }}</td>
                        <td>@if ($canPublish)<form method="POST" action="{{ route('panel.settings.chrome.rollback', [$area, $v->id]) }}" data-confirm="Sürüm {{ $v->number }} yayınlansın mı? Mevcut yapılandırma yeni sürüm olarak saklanır.">@csrf<input type="hidden" name="website" value="{{ $website->id }}"><button type="submit" class="btn btn--ghost btn--pill">Bu sürüme dön</button></form>@endif</td></tr>
                @endforeach
            </tbody></table>
        @endif
    </div>

    @if ($area === 'footer')
        <div class="panel" style="margin-top:18px">
            <p class="eyebrow">Yasal metin sürümleri (KVKK kanıtı)</p>
            <p class="small muted" style="margin:0 0 10px">Yukarıda seçilen yasal sayfalar her footer yayınında ve sayfa yeniden yayınlandığında denetlenir; gövde değiştiyse yeni, değiştirilemez sürüm açılır. Vitrin formlarındaki her KVKK onayı o anki sürüme bağlanır (rıza kaydı).</p>
            @if ($legalVersions->isEmpty())<p class="body-muted small" style="margin:0">Henüz yasal metin sürümü yok — KVKK sayfasını seçip footer'ı yayınlayın. Üretimde <span class="mono">ofisvio:doctor</span> KVKK sürümü olmadan uyarır.</p>@else
                <table class="data"><thead><tr><th>Metin</th><th>Sürüm</th><th>Sayfa</th><th>Özet</th><th>Yayın</th><th>Not</th></tr></thead><tbody>
                    @foreach ($legalVersions as $lv)
                        <tr><td>{{ \App\Models\LegalDocumentVersion::KINDS[$lv->kind] ?? $lv->kind }}</td><td class="mono">v{{ $lv->version }}</td><td class="small">{{ $lv->title }}</td><td class="mono small">{{ substr($lv->content_hash, 0, 12) }}…</td><td class="small">{{ $lv->published_at->format('d.m.Y H:i') }}</td><td class="small">{{ $lv->note ?? '—' }}</td></tr>
                    @endforeach
                </tbody></table>
            @endif
        </div>
    @endif

    <template id="tpl-menu"><div class="panel" style="padding:10px;background:var(--surface-2)" data-row><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span data-handle title="Sürükle" style="cursor:grab;user-select:none">⋮⋮</span><input class="control" type="text" data-field="[label]" placeholder="Başlık" maxlength="60" style="flex:1 1 160px"><input class="control mono" type="text" data-field="[href]" placeholder="/cozumler ya da #cozumler ya da https://…" maxlength="300" style="flex:2 1 220px"><label class="checkbox-row small"><input type="checkbox" data-field="[mega]" value="1"><span>mega menü</span></label><label class="checkbox-row small"><input type="checkbox" data-field="[new_tab]" value="1"><span>yeni sekme</span></label><button type="button" class="btn btn--ghost btn--pill" data-add-child data-add="children">+ Alt öğe</button><button type="button" class="btn btn--ghost btn--pill" data-remove style="color:var(--danger)">Sil</button></div><div class="stack" style="gap:6px;margin:8px 0 0 28px" data-list data-child="[children]" data-child-list data-template="tpl-child"></div></div></template>
    <template id="tpl-child"><div style="display:flex;gap:6px;flex-wrap:wrap" data-row><span data-handle style="cursor:grab;user-select:none">⋮</span><input class="control" type="text" data-field="[label]" placeholder="Alt başlık" maxlength="60" style="flex:1 1 140px"><input class="control mono" type="text" data-field="[href]" placeholder="/cozum/sanal-ofis" maxlength="300" style="flex:2 1 200px"><input class="control" type="text" data-field="[description]" placeholder="Kısa açıklama (mega)" maxlength="120" style="flex:2 1 200px"><button type="button" class="btn btn--ghost btn--pill" data-remove>×</button></div></template>
    <template id="tpl-column"><div class="panel" style="padding:10px;background:var(--surface-2)" data-row><div style="display:flex;gap:6px;align-items:center"><span data-handle style="cursor:grab;user-select:none">⋮⋮</span><input class="control" type="text" data-field="[title]" placeholder="Kolon başlığı" maxlength="60" style="flex:1"><button type="button" class="btn btn--ghost btn--pill" data-remove style="color:var(--danger)">Sil</button></div><div class="stack" style="gap:6px;margin-top:8px" data-list data-child="[items]" data-child-list data-template="tpl-item"></div><button type="button" class="btn btn--ghost btn--pill" data-add-child data-add="items" style="margin-top:6px">+ Bağlantı</button></div></template>
    <template id="tpl-item"><div style="display:flex;gap:6px" data-row><span data-handle style="cursor:grab;user-select:none">⋮</span><input class="control" type="text" data-field="[label]" placeholder="Bağlantı metni" maxlength="80" style="flex:1"><input class="control mono" type="text" data-field="[href]" placeholder="/blog" maxlength="300" style="flex:1"><button type="button" class="btn btn--ghost btn--pill" data-remove>×</button></div></template>
    <template id="tpl-social"><div style="display:flex;gap:6px;flex-wrap:wrap" data-row><span data-handle style="cursor:grab;user-select:none">⋮</span><select class="control" data-field="[network]" style="flex:0 1 160px">@foreach ($social as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select><input class="control mono" type="url" data-field="[url]" placeholder="https://…" maxlength="300" style="flex:1 1 260px"><button type="button" class="btn btn--ghost btn--pill" data-remove>×</button></div></template>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_v('js/chrome-editor.js') }}" defer></script>
@endpush
