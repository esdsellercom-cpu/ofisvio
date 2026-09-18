{{-- Entegrasyon alanı (faz 61b): secret → maskeli + "Yeni anahtar gir"; env'den geliyorsa bilgi notu. --}}
<label class="field">
    <span class="label">{{ $f['label'] }}@if (! empty($f['required'])) <span style="color:var(--danger)">*</span>@endif</span>
    @if ($f['type'] === 'secret')
        @if ($f['defined'])
            <span class="mono" style="display:block;margin-bottom:4px">{{ $f['masked'] }} <span class="small muted">({{ $f['from_env'] ? 'env' : 'panel, şifreli' }})</span></span>
        @endif
        <input class="control mono" type="password" name="f[{{ $f['key'] }}]" autocomplete="new-password" placeholder="{{ $f['defined'] ? 'Yeni anahtar gir (boş = mevcut korunur)' : 'Anahtar' }}" @disabled(! $canSecrets)>
        @if ($f['defined'] && ! $f['from_env'] && $canSecrets)<label class="checkbox-row small" style="margin-top:4px"><input type="checkbox" name="f[clear_{{ $f['key'] }}]" value="1"><span>Panelde saklanan anahtarı sil</span></label>@endif
    @elseif ($f['type'] === 'select')
        <select class="control" name="f[{{ $f['key'] }}]" @disabled(! $canManage)><option value="">— {{ $f['from_env'] ? 'env: '.$f['value'] : 'seçin' }} —</option>@foreach ($f['options'] as $k => $l)<option value="{{ $k }}" @selected(! $f['from_env'] && $f['value'] === $k)>{{ $l }}</option>@endforeach</select>
    @else
        <input class="control {{ in_array($f['type'], ['url', 'int'], true) ? 'mono' : '' }}" type="{{ $f['type'] === 'int' ? 'number' : 'text' }}" name="f[{{ $f['key'] }}]" value="{{ $f['from_env'] ? '' : $f['value'] }}" placeholder="{{ $f['from_env'] ? 'env: '.$f['value'] : (string) ($f['default'] ?? '') }}" @disabled(! $canManage)>
    @endif
    @if (! empty($f['help']))<span class="small muted">{{ $f['help'] }}</span>@endif
    @if ($f['from_env'])<span class="small muted">Env'den geliyor ({{ $f['env'] ?? '' }}); buraya değer girerseniz panel değeri öncelik kazanır.</span>@endif
</label>
