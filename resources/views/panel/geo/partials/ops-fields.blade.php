{{-- Faz 45: olanaklar, kapak görseli (lokasyon galerisi), bakım durumu — oda ve alan formlarında ortak. $m (Room|Space|null), $gallery --}}
<div class="grid-auto" style="--min:200px;--gap:12px">
    <label class="field" style="grid-column:1/-1"><span class="label">Olanaklar (virgülle)</span>
        <input class="control" type="text" name="amenities" value="{{ implode(', ', $m?->amenityList() ?? []) }}" maxlength="1000" placeholder="Projektör, Beyaz tahta, 55&quot; ekran, Klima">
    </label>
    <label class="field"><span class="label">Kapak görseli</span>
        <select class="control" name="cover_media_id">
            <option value="">— yok —</option>
            @foreach ($gallery as $link)
                @continue($link->media === null)
                <option value="{{ $link->media_id }}" @selected(($m?->cover_media_id) === $link->media_id)>{{ $link->media->alt ?: $link->media->original_name ?? ('Görsel #'.$link->media_id) }} ({{ $link->category }})</option>
            @endforeach
        </select>
        <span class="small muted">Lokasyon galerisinden; yeni görsel <a href="{{ route('panel.geo.media.index', $location) }}">Görseller</a> ekranında yüklenir.</span>
    </label>
    <label class="field"><span class="label">Bakım bitişi</span>
        <input class="control mono" type="date" name="maintenance_until" value="{{ $m?->maintenance_until?->format('Y-m-d') }}">
        <span class="small muted">Doluysa o tarihe kadar rezervasyon/tahsis kapalı; boş = bakım yok.</span>
    </label>
    <label class="field"><span class="label">Bakım notu</span>
        <input class="control" type="text" name="maintenance_note" value="{{ $m?->maintenance_note }}" maxlength="200" placeholder="Klima değişimi">
    </label>
</div>
@error('maintenance_note')<p class="field-error">{{ $message }}</p>@enderror
@error('cover_media_id')<p class="field-error">{{ $message }}</p>@enderror