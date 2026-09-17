@extends('layouts.panel')

@section('title', 'Belge şablonu · '.$kinds[$kind])

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.collections.index') }}#belgeler">Tahsilat &amp; belgeler</a> · Belge ayarları</p>
            <h1 class="h2">{{ $kinds[$kind] }} şablonu</h1>
            <p>Logo, başlık, metinler, tablo sütunları, imza/kaşe ve alt bilgi. Metin alanlarında <code>@{{yer_tutucu}}</code> yazın; sağdaki önizleme gerçek son kayıtla (yoksa örnek etiketlerle) yazarken güncellenir.</p>
        </div>
        <div class="panel-head__actions">
            <nav class="tabbar" aria-label="Şablon türleri" style="border:0">
                @foreach ($kinds as $key => $label)<a href="{{ route('panel.collections.templates.edit', $key) }}" @if ($key === $kind) aria-current="page" @endif>{{ $label }}</a>@endforeach
            </nav>
        </div>
    </div>

    <div class="grid g-1-2" style="align-items:start">
        <form method="POST" action="{{ route('panel.collections.templates.update', $kind) }}" class="card" data-live-preview>
            @csrf @method('PUT')
            <div class="card__head"><h3>Alanlar</h3>@unless ($canEdit)<span class="sub">salt okunur (invoice.issue gerekir)</span>@endunless</div>
            <div class="card__body stack" style="gap:10px">
                @error('heading')<div class="field-error">{{ $message }}</div>@enderror
                @foreach ($fieldDefs as $key => $def)
                    @if ($def['type'] === 'bool')
                        <label class="checkbox-row"><input type="checkbox" name="{{ $key }}" value="1" @checked(! empty($fields[$key])) @disabled(! $canEdit)><span><strong>{{ $def['label'] }}</strong> — {{ $def['description'] }}</span></label>
                    @elseif ($def['type'] === 'columns')
                        <div class="field"><span class="label">{{ $def['label'] }}</span>
                            <div style="display:flex;flex-wrap:wrap;gap:6px 14px">
                                @foreach ($columnOptions as $col => $label)
                                    <label class="checkbox-row"><input type="checkbox" name="columns[]" value="{{ $col }}" @checked(in_array($col, (array) ($fields['columns'] ?? []), true)) @disabled(! $canEdit)><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            <span class="small muted">{{ $def['description'] }}</span>
                        </div>
                    @elseif ($def['type'] === 'textarea')
                        <label class="field"><span class="label">{{ $def['label'] }}</span><textarea class="control" name="{{ $key }}" style="min-height:{{ $key === 'footer' ? 56 : 84 }}px" @disabled(! $canEdit)>{{ $fields[$key] ?? '' }}</textarea><span class="small muted">{{ $def['description'] }}</span></label>
                    @elseif ($def['type'] === 'color')
                        <label class="field"><span class="label">{{ $def['label'] }}</span><input type="color" name="{{ $key }}" value="{{ $fields[$key] ?? '#1f5f4b' }}" @disabled(! $canEdit) style="width:60px;height:34px;border:1px solid var(--line);border-radius:6px;background:none"><span class="small muted">{{ $def['description'] }}</span></label>
                    @else
                        <label class="field"><span class="label">{{ $def['label'] }}</span><input class="control {{ $def['type'] === 'url' ? 'mono' : '' }}" type="text" name="{{ $key }}" value="{{ $fields[$key] ?? '' }}" @disabled(! $canEdit) placeholder="{{ $def['type'] === 'url' ? 'https://… ya da /images/logo.png' : '' }}"><span class="small muted">{{ $def['description'] }}</span></label>
                    @endif
                @endforeach
                <details>
                    <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Dinamik alanlar</summary>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                        @foreach ($placeholders as $key => $label)<code class="tag" title="{{ $label }}">@{{ {{ $key }} }}</code>@endforeach
                    </div>
                </details>
            </div>
            @if ($canEdit)
                <div class="modal__foot" style="border-radius:0 0 var(--r) var(--r)">
                    <button type="submit" name="action" value="preview" class="btn btn--ghost">Sunucuda önizle</button>
                    <button type="submit" name="action" value="save" class="btn btn--brand">Şablonu kaydet</button>
                </div>
            @endif
        </form>

        <div class="card">
            <div class="card__head"><h3>Gerçek belge önizlemesi</h3><span class="sub">{{ str_contains($sample['customer_name'] ?? '', '«') ? 'örnek etiketlerle (henüz kayıt yok)' : 'son gerçek kayıtla' }}</span></div>
            <div class="card__body" style="background:var(--surface-3);padding:18px">
                <div data-preview data-sample="{{ json_encode($sample) }}" data-column-labels="{{ json_encode($columnOptions) }}" style="box-shadow:var(--shadow);border-radius:4px;overflow:hidden">{!! $preview !!}</div>
            </div>
        </div>
    </div>
@endsection
