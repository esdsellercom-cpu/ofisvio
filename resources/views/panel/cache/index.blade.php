@extends('layouts.panel')

@section('title', 'Önbellek')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Cache Command Center · v1</p>
            <h1 class="h2">Önbellek</h1>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:70ch">
        Her site kendi sürüm sayacıyla önbelleklenir; geçersizleme yalnızca o siteyi etkiler. Yayın akışı
        (yayınla / yayından kaldır / zamanlanmış yayın) sürümü kendiliğinden artırır. Elle geçersizleme
        JIT erişimi ister; tüm önbelleği boşaltmak yıkıcıdır (oturum ve hız sınırı sayaçları dahil).
    </p>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Site</th><th class="num">Sürüm</th><th class="num">İsabet</th><th class="num">Iskalama</th><th class="num">Oran</th><th class="num">Purge</th><th></th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    @php($site = $row['website'])
                    @php($s = $row['stats'])
                    <tr>
                        <td>{{ $site->name }} @if ($site->is_default)<span class="badge badge--ok" style="margin-left:6px">Varsayılan</span>@endif</td>
                        <td class="num mono">v{{ $s['version'] }}</td>
                        <td class="num mono">{{ $s['hits'] }}</td>
                        <td class="num mono">{{ $s['misses'] }}</td>
                        <td class="num mono">{{ $s['hit_ratio'] === null ? '—' : number_format($s['hit_ratio'] * 100, 1).'%' }}</td>
                        <td class="num mono">{{ $s['purges'] }}</td>
                        <td>
                            <div class="row-actions">
                                @if ($canInspect)
                                    <a href="{{ route('panel.cache.inspect', $site) }}" class="btn btn--ghost btn--pill">Anahtarlar</a>
                                @endif
                                @can('cache.warm')
                                    <form method="POST" action="{{ route('panel.cache.warm', $site) }}">@csrf
                                        <button type="submit" class="btn btn--ghost btn--pill">Isıt</button>
                                    </form>
                                @endcan
                                @if ($row['grant'])
                                    <form method="POST" action="{{ route('panel.cache.purge', $site) }}">@csrf
                                        <button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger);border-color:#E9C4BC">Geçersiz kıl</button>
                                    </form>
                                @elseif ($canRequestJit)
                                    <a href="#jit-{{ $site->id }}" class="btn btn--ghost btn--pill">JIT iste</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canRequestJit)
        <div class="panel" style="margin-top:20px">
            <p class="eyebrow">Geçersizleme erişimi (JIT)</p>
            <p class="body-muted" style="margin:0 0 16px">
                Matris: <code>cache.invalidate</code> JIT ister. Talep gerekçeli ve sürelidir, denetim kaydına yazılır.
            </p>
            <div class="grid-auto" style="--min:280px;--gap:14px">
                @foreach ($rows as $row)
                    @continue($row['grant'])
                    @php($site = $row['website'])
                    <div id="jit-{{ $site->id }}" style="border:1px solid var(--line);border-radius:var(--r-md);padding:16px 18px">
                        <strong>{{ $site->name }}</strong>
                        <form method="POST" action="{{ route('panel.cache.jit', $site->id) }}" class="stack" style="gap:10px;margin-top:10px">
                            @csrf
                            <label class="field"><span class="label">Gerekçe</span>
                                <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px" placeholder="Örn. yanlış fiyat yayınlandı, düzeltme sonrası proxy kopyası"></textarea>
                            </label>
                            <div class="inline-form">
                                <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                                    <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                                </label>
                                <button type="submit" class="btn btn--brand">Erişim aç</button>
                            </div>
                        </form>
                    </div>
                @endforeach

                <div id="jit-0" style="border:1px solid #E9C4BC;border-radius:var(--r-md);padding:16px 18px;background:var(--danger-wash)">
                    <strong style="color:var(--danger)">Tüm önbellek (yıkıcı)</strong>
                    @if ($globalGrant)
                        <form method="POST" action="{{ route('panel.cache.purge-all') }}" style="margin-top:10px">@csrf
                            <button type="submit" class="btn btn--ghost" style="color:var(--danger);border-color:#E9C4BC">Tüm önbelleği boşalt</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('panel.cache.jit', 0) }}" class="stack" style="gap:10px;margin-top:10px">
                            @csrf
                            <label class="field"><span class="label">Gerekçe</span>
                                <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:64px"></textarea>
                            </label>
                            <div class="inline-form">
                                <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                                    <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                                </label>
                                <button type="submit" class="btn btn--brand">Erişim aç</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
    {{-- cache.settings (JIT): TTL + HTTP süreleri, site başına --}}
    @if ($canRequestSettingsJit)
        <div class="panel" style="margin-top:20px">
            <p class="eyebrow">Önbellek ayarları (JIT)</p>
            <p class="body-muted" style="margin:0 0 16px">
                Uygulama önbelleği TTL'i ve misafir HTTP başlıkları (<code>max-age</code> tarayıcı, <code>s-maxage</code> proxy/CDN).
                Boş = kod varsayılanı ({{ $defaults['ttl'] }} sn / {{ $defaults['max_age'] }} / {{ $defaults['s_maxage'] }}). Değişiklik JIT ister; kaydedince site önbelleği sürüm atlar.
            </p>
            <div class="grid-auto" style="--min:280px;--gap:14px">
                @foreach ($rows as $row)
                    @php($site = $row['website'])
                    <div style="border:1px solid var(--line);border-radius:var(--r-md);padding:16px 18px">
                        <strong>{{ $site->name }}</strong>
                        <div class="small muted mono" style="margin-top:4px">TTL {{ $site->cache_ttl_seconds ?? $defaults['ttl'] }} sn · max-age {{ $site->http_max_age ?? $defaults['max_age'] }} · s-maxage {{ $site->http_s_maxage ?? $defaults['s_maxage'] }}</div>
                        @if ($row['settingsGrant'])
                            <form method="POST" action="{{ route('panel.cache.settings', $site) }}" class="stack" style="gap:10px;margin-top:10px">
                                @csrf @method('PUT')
                                <div class="grid-auto" style="--min:80px;--gap:10px">
                                    <label class="field"><span class="label">TTL (sn)</span><input class="control mono" type="number" name="cache_ttl_seconds" value="{{ old('cache_ttl_seconds', $site->cache_ttl_seconds) }}" min="30" max="86400" placeholder="{{ $defaults['ttl'] }}"></label>
                                    <label class="field"><span class="label">max-age</span><input class="control mono" type="number" name="http_max_age" value="{{ old('http_max_age', $site->http_max_age) }}" min="0" max="86400" placeholder="{{ $defaults['max_age'] }}"></label>
                                    <label class="field"><span class="label">s-maxage</span><input class="control mono" type="number" name="http_s_maxage" value="{{ old('http_s_maxage', $site->http_s_maxage) }}" min="0" max="604800" placeholder="{{ $defaults['s_maxage'] }}"></label>
                                </div>
                                <div><button type="submit" class="btn btn--brand">Kaydet</button></div>
                            </form>
                        @else
                            <form method="POST" action="{{ route('panel.cache.jit', $site->id) }}" class="stack" style="gap:10px;margin-top:10px">
                                @csrf
                                <input type="hidden" name="izin" value="settings">
                                <label class="field"><span class="label">Gerekçe</span>
                                    <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:56px"></textarea>
                                </label>
                                <div class="inline-form">
                                    <label class="field" style="flex:0 1 140px"><span class="label">Süre (dk)</span>
                                        <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                                    </label>
                                    <button type="submit" class="btn btn--ghost">Ayar erişimi aç</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endsection
