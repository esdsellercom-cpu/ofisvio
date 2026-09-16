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
@endsection
