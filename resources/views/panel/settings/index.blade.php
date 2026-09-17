@extends('layouts.panel')

@section('title', 'Ayarlar')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Ayar merkezi · {{ $location ? 'Lokasyon: '.$location->name : 'Kurulum (varsayılan)' }}</p>
            <h1 class="h2">{{ $group === 'general' ? 'Yerelleştirme' : 'Site & sistem ayarları' }}</h1>
            @if ($group === 'general')<p>Saat dilimi ve para birimi. Çok dilli içerik (yerel/dil başına sayfa) yol haritasında; bugün vitrin tek dilde yayınlanır.</p>@endif
        </div>
        <div class="panel-head__actions">
            <form method="GET" class="inline-form">
                @if ($group)<input type="hidden" name="grup" value="{{ $group }}">@endif
                <label class="field" style="flex:1 1 220px"><span class="label">Kapsam</span>
                    <select class="control" name="lokasyon" onchange="this.form.requestSubmit()">
                        <option value="">Kurulum (tüm lokasyonlar)</option>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected($location && $location->id === $loc->id)>{{ $loc->name }} (üzerine yaz)</option>
                        @endforeach
                    </select>
                </label>
            </form>
        </div>
    </div>

    @error('settings')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <p class="small muted" style="margin:0 0 18px">Kalıtım: lokasyon › şirket › organizasyon › kurulum › varsayılan. Boş bırakılan alan bir üst kapsamdan miras alır. Site marka/iletişim bilgileri <a href="{{ route('panel.websites.index') }}">Websiteler</a>'de, bildirim alıcıları <a href="{{ route('panel.notifications.index') }}">Bildirim Merkezi</a>'nde.</p>

    <form method="POST" action="{{ route('panel.settings.update', $location ? ['lokasyon' => $location->id] : []) }}" class="stack" style="gap:20px">
        @csrf @method('PUT')
        @if ($group)<input type="hidden" name="grup" value="{{ $group }}">@endif
        @foreach ($groups as $groupKey => $groupLabel)
            @continue(empty($data[$groupKey]))
            <div class="panel stack" style="gap:14px">
                <p class="eyebrow" style="margin:0">{{ $groupLabel }}</p>
                <div class="grid-auto" style="--min:260px;--gap:14px">
                    @foreach ($data[$groupKey] as $row)
                        @php($field = str_replace('.', '__', $row['key']))
                        @php($def = $row['def'])
                        <div class="field">
                            <span class="label">{{ $def['label'] }}</span>
                            @if ($def['type'] === 'bool')
                                <label class="checkbox-row"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $row['stored'] ?? $row['effective']))><span>{{ $def['description'] }}</span></label>
                                @if ($scope !== 'installation')
                                    <label class="checkbox-row small"><input type="checkbox" name="{{ $field }}_inherit" value="1" @checked($row['stored'] === null)><span>Miras al (üst kapsam: {{ $row['effective'] ? 'açık' : 'kapalı' }})</span></label>
                                @endif
                            @elseif ($def['type'] === 'select')
                                <select class="control" name="{{ $field }}">
                                    @if ($scope !== 'installation')<option value="">— miras: {{ $def['options'][$row['effective']] ?? $row['effective'] }} —</option>@endif
                                    @foreach ($def['options'] ?? [] as $v => $label)
                                        <option value="{{ $v }}" @selected((string) old($field, $row['stored'] ?? ($scope === 'installation' ? $row['effective'] : '')) === (string) $v)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="small muted">{{ $def['description'] }}</span>
                            @else
                                <input class="control {{ $def['type'] === 'int' ? 'mono' : '' }}" type="{{ $def['type'] === 'int' ? 'number' : 'text' }}" name="{{ $field }}" value="{{ old($field, $row['stored'] ?? ($scope === 'installation' ? $row['effective'] : '')) }}" placeholder="{{ $scope === 'installation' ? '' : 'miras: '.$row['effective'] }}">
                                <span class="small muted">{{ $def['description'] }}</span>
                            @endif
                            @error($field)<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        @can('settings.manage')
            <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
        @else
            <p class="small muted">Yalnız görüntüleme (settings.manage yok).</p>
        @endcan
    </form>
@endsection
