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