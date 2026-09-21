{{-- Site genel ayarları alanları (faz 29) — personel site formu ve müşteri menü ekranı paylaşır. $site: Website --}}
<div class="grid-auto" style="--min:220px;--gap:12px">
    <label class="field"><span class="label">Telefon</span>
        <input class="control mono" type="tel" name="contact_phone" value="{{ old('contact_phone', $site->contact_phone) }}" maxlength="32" placeholder="0850 840 00 00" @error('contact_phone') aria-invalid="true" @enderror>
    </label>
    <label class="field"><span class="label">E-posta</span>
        <input class="control" type="email" name="contact_email" value="{{ old('contact_email', $site->contact_email) }}" maxlength="190" placeholder="merhaba@ornek.com" @error('contact_email') aria-invalid="true" @enderror>
        @error('contact_email')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
    </label>
</div>
<label class="field"><span class="label">Slogan (footer)</span>
    <input class="control" type="text" name="tagline" value="{{ old('tagline', $site->tagline) }}" maxlength="200">
</label>
<label class="field"><span class="label">Adres</span>
    <input class="control" type="text" name="address" value="{{ old('address', $site->address) }}" maxlength="300">
</label>
<div class="grid-auto" style="--min:220px;--gap:12px">
    <label class="field"><span class="label">WhatsApp (E.164, +90…)</span>
        <input class="control mono" type="tel" name="whatsapp_number" value="{{ old('whatsapp_number', $site->whatsapp_number) }}" maxlength="20" placeholder="+905001234567" @error('whatsapp_number') aria-invalid="true" @enderror>
        @error('whatsapp_number')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span class="label">Çalışma saatleri (satır başına)</span>
        <textarea class="control" name="business_hours" style="min-height:64px" placeholder="Pzt–Cum 08:30–19:00&#10;Cmt 09:00–14:00">{{ old('business_hours', implode("\n", $site->business_hours ?? [])) }}</textarea>
    </label>
</div>
<div class="grid-auto" style="--min:220px;--gap:12px">
    <label class="field"><span class="label">Duyuru şeridi metni (boş: şerit yok)</span>
        <input class="control" type="text" name="announcement_text" value="{{ old('announcement_text', $site->announcement_text) }}" maxlength="160" placeholder="Örn. Yeni şube: Konya Merkez açıldı">
    </label>
    <label class="field"><span class="label">Duyuru bağlantısı (/yol, #bolum, https://)</span>
        <input class="control mono" type="text" name="announcement_href" value="{{ old('announcement_href', $site->announcement_href) }}" maxlength="300" @error('announcement_href') aria-invalid="true" @enderror>
        @error('announcement_href')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
    </label>
    <label class="field"><span class="label">Duyuru bitişi (boş: süresiz)</span>
        <input class="control mono" type="datetime-local" name="announcement_until" value="{{ old('announcement_until', $site->announcement_until?->format('Y-m-d\TH:i')) }}">
    </label>
</div>
<details style="margin-top:6px">
    <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Marka renkleri ve yazı tipi (boş = tema varsayılanı)</summary>
    @php($bs = (array) old('brand_style_x', $site->brand_style ?? []))
    <div class="grid-auto" style="--min:150px;--gap:10px;margin-top:10px">
        @foreach (['brand' => 'Ana renk', 'brand_deep' => 'Koyu ton (hover)', 'brand_light' => 'Açık ton (koyu zeminde vurgu)', 'brand_wash' => 'Yumuşak zemin', 'ink' => 'Metin rengi', 'surface' => 'Sayfa zemini'] as $key => $label)
            <label class="field"><span class="label">{{ $label }}</span>
                <input class="control mono" type="text" name="{{ $key }}" value="{{ old($key, $bs[$key] ?? '') }}" placeholder="#1F5B45" maxlength="7" pattern="#[0-9a-fA-F]{6}" @error($key) aria-invalid="true" @enderror>
            </label>
        @endforeach
        <label class="field"><span class="label">Sans yazı tipi</span>
            <select class="control" name="font_sans">@foreach (\App\Site\BrandStyle::SANS as $k => $f)<option value="{{ $k }}" @selected(old('font_sans', $bs['font_sans'] ?? 'instrument') === $k)>{{ $f['label'] }}</option>@endforeach</select>
        </label>
        <label class="field"><span class="label">Serif yazı tipi (vurgu)</span>
            <select class="control" name="font_serif">@foreach (\App\Site\BrandStyle::SERIF as $k => $f)<option value="{{ $k }}" @selected(old('font_serif', $bs['font_serif'] ?? 'instrument') === $k)>{{ $f['label'] }}</option>@endforeach</select>
        </label>
    </div>
    <p class="small muted" style="margin:8px 0 0">Renkler tema preset'inin üstüne uygulanır; fontlar yalnız listedeki (Google Fonts) ailelerden seçilir — serbest CSS/URL kabul edilmez.</p>
</details>
