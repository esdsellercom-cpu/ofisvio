@extends('layouts.panel')

@section('title', 'Yönlendirmeler & 404')

{{-- Akıllı URL merkezi (faz 54). Sekmeler: yönlendirmeler (manuel + otomatik kayıtlar) · öneriler (onay bekleyen) ·
     404 günlüğü · URL geçmişi · kırık URL botu. Üstte site seçimi ve istatistik kartları. --}}
@section('content')
    @php($levelLabel = ['critical' => 'Kritik', 'warning' => 'Uyarı', 'suggestion' => 'Öneri', 'fixed' => 'Düzeltildi'])
    @php($levelClass = ['critical' => 'badge--danger', 'warning' => 'badge--warn', 'suggestion' => 'badge--info', 'fixed' => 'badge--ok'])
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.index') }}">SEO & GEO</a> · Akıllı URL</p>
            <h1 class="h2">Yönlendirmeler & 404</h1>
            <p class="body-muted" style="margin:6px 0 0;max-width:80ch">Eski adres → URL geçmişi → yönlendirme tablosu → benzerlik analizi → üst kategori → ana sayfa. Eşik altı eşleşme sessizce yönlendirilmez; öneri olarak burada onay bekler. Eşikler: <a href="{{ route('panel.seo.settings.show', [$website, 'yonlendirme']) }}">Gelişmiş ayarlar › Yönlendirme & 404</a>.</p>
        </div>
        @if ($websites->count() > 1)
            <form method="GET" action="{{ route('panel.seo.redirects.home') }}" class="inline-form">
                <label class="field"><span class="label">Site</span>
                    <select class="control" onchange="location.href=this.value">
                        @foreach ($websites as $w)<option value="{{ route('panel.seo.redirects.index', [$w, $tab]) }}" @selected($w->id === $website->id)>{{ $w->name }}</option>@endforeach
                    </select>
                </label>
            </form>
        @endif
    </div>

    @if (session('status'))<div class="alert alert--ok">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert alert--danger" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <div class="kpis" style="margin-bottom:18px">
        <a href="{{ route('panel.seo.redirects.index', [$website, 'yonlendirmeler']) }}" class="kpi"><span class="k">Toplam yönlendirme</span><span class="v">{{ $stats['redirects'] }}</span></a>
        <a href="{{ route('panel.seo.redirects.index', [$website, '404']) }}" class="kpi {{ $stats['open_404'] > 0 ? 'watch' : '' }}"><span class="k">Açık 404</span><span class="v">{{ $stats['open_404'] }}</span></a>
        <div class="kpi ok"><span class="k">Çözülen URL</span><span class="v">{{ $stats['resolved'] }}</span></div>
        <a href="{{ route('panel.seo.redirects.index', [$website, 'oneriler']) }}" class="kpi {{ $stats['pending'] > 0 ? 'watch' : '' }}"><span class="k">Bekleyen öneri</span><span class="v">{{ $stats['pending'] }}</span></a>
        <div class="kpi {{ $stats['chains'] > 0 ? 'watch' : '' }}"><span class="k">Redirect chain</span><span class="v">{{ $stats['chains'] }}</span></div>
        <div class="kpi {{ $stats['loops'] > 0 ? 'watch' : '' }}"><span class="k">Redirect loop</span><span class="v">{{ $stats['loops'] }}</span></div>
    </div>

    <nav class="tabbar" aria-label="Yönlendirme sekmeleri">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('panel.seo.redirects.index', [$website, $key]) }}" @if ($key === $tab) aria-current="page" @endif>{{ $label }}@if ($key === 'oneriler' && $stats['pending'] > 0) <span class="badge badge--warn">{{ $stats['pending'] }}</span>@endif</a>
        @endforeach
    </nav>

    {{-- ---------------- Yönlendirmeler ---------------- --}}
    @if ($tab === 'yonlendirmeler')
        @if ($canEdit)
            @php($e = $editing)
            <form method="POST" action="{{ $e ? route('panel.seo.redirects.update', [$website, $e]) : route('panel.seo.redirects.store', $website) }}" class="panel" style="margin-bottom:18px" data-redirect-form>
                @csrf @if ($e) @method('PUT') @endif
                <p class="label" style="margin-bottom:10px">{{ $e ? 'Yönlendirmeyi düzenle' : 'Yeni yönlendirme' }}</p>
                <div class="grid-auto" style="--min:180px;--gap:12px">
                    <label class="field" style="grid-column:span 2"><span class="label">Eski URL</span><input class="control mono" type="text" name="from_path" value="{{ old('from_path', $e?->from_path ?? request('from')) }}" required placeholder="/blog/eski-yazi"></label>
                    <label class="field" style="grid-column:span 2"><span class="label">Yeni URL</span><input class="control mono" type="text" name="to_path" value="{{ old('to_path', $e?->to_path) }}" required placeholder="/blog/yeni-yazi ya da https://…"></label>
                    <label class="field"><span class="label">Redirect type</span>
                        <select class="control" name="code">@foreach (\App\Models\UrlRedirect::CODES as $code => $label)<option value="{{ $code }}" @selected((int) old('code', $e?->code ?? 301) === $code)>{{ $label }}</option>@endforeach</select>
                    </label>
                    <label class="field"><span class="label">Durum</span>
                        <select class="control" name="status">
                            <option value="active" @selected(old('status', $e?->status ?? 'active') === 'active')>Etkin</option>
                            <option value="disabled" @selected(old('status', $e?->status) === 'disabled')>Pasif</option>
                            @if ($e?->status === 'pending')<option value="pending" selected>Onay bekliyor</option>@endif
                        </select>
                    </label>
                    <label class="field" style="grid-column:1/-1"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note', $e?->note) }}" maxlength="500"></label>
                </div>
                <div style="display:flex;gap:10px;margin-top:12px;align-items:center;flex-wrap:wrap">
                    <button type="submit" class="btn btn--brand">{{ $e ? 'Güncelle' : 'Ekle' }}</button>
                    @if ($e)<a href="{{ route('panel.seo.redirects.index', [$website, 'yonlendirmeler']) }}" class="btn btn--ghost">Vazgeç</a>@endif
                    <span class="small muted">Kalıcı değişikliklerde 301. Hedef başka bir yönlendirmenin kaynağıysa zincir otomatik düzleştirilir; döngü reddedilir.</span>
                </div>
            </form>
        @endif

        <form method="GET" class="inline-form" style="margin-bottom:12px">
            <select class="control" name="durum" onchange="this.form.submit()">
                <option value="all" @selected($status === 'all')>Tüm durumlar</option>
                @foreach (\App\Models\UrlRedirect::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
            </select>
            <input class="control mono" type="search" name="q" value="{{ $q }}" placeholder="adres ara"><button type="submit" class="btn btn--ghost">Ara</button>
        </form>

        <div class="panel" style="padding:0">
            <table class="table">
                <thead><tr><th>Eski URL</th><th>Yeni URL</th><th>Kod</th><th>Durum</th><th>Kaynak</th><th>Skor</th><th>İsabet</th><th>Not</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td class="mono small">{{ $r->from_path }}</td>
                            <td class="mono small">{{ $r->to_path }}</td>
                            <td class="mono">{{ $r->code }}</td>
                            <td><span class="badge {{ $r->status === 'active' ? 'badge--ok' : ($r->status === 'pending' ? 'badge--warn' : 'badge--muted') }}">{{ \App\Models\UrlRedirect::STATUSES[$r->status] ?? $r->status }}</span></td>
                            <td class="small">{{ \App\Models\UrlRedirect::SOURCES[$r->source] ?? $r->source }}</td>
                            <td class="mono small">{{ $r->score !== null ? '%'.$r->score : '—' }}</td>
                            <td class="mono small">{{ $r->hits }}@if ($r->last_hit_at)<br><span class="muted">{{ $r->last_hit_at->format('d.m.Y') }}</span>@endif</td>
                            <td class="small muted">{{ $r->note }}</td>
                            <td>
                                @if ($canEdit)
                                    <div class="row-actions">
                                        <a href="{{ route('panel.seo.redirects.index', [$website, 'yonlendirmeler', 'duzenle' => $r->id]) }}" class="btn btn--ghost btn--pill">Düzenle</a>
                                        <form method="POST" action="{{ route('panel.seo.redirects.destroy', [$website, $r]) }}" onsubmit="return confirm('Yönlendirme silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Sil</button></form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">Kayıt yok. Slug değişince ve içerik silinince kayıtlar otomatik oluşur.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $rows->links() }}</div>

    {{-- ---------------- Öneriler ---------------- --}}
    @elseif ($tab === 'oneriler')
        <p class="body-muted" style="margin:0 0 12px;max-width:80ch">Benzerlik skoru onay eşiği ile otomatik eşik arasında kalan eşleşmeler. Onaylanınca etkin yönlendirme olur; hedefi değiştirebilir ya da reddedebilirsiniz.</p>
        <div class="panel" style="padding:0">
            <table class="table">
                <thead><tr><th>Eski URL</th><th>Önerilen hedef</th><th>Skor</th><th>Neden</th><th></th></tr></thead>
                <tbody>
                    @forelse ($pending as $p)
                        <tr>
                            <td class="mono small">{{ $p->from_path }}</td>
                            <td class="mono small">{{ $p->to_path }}</td>
                            <td><span class="badge {{ $p->score >= 75 ? 'badge--ok' : 'badge--warn' }}">%{{ $p->score }}</span></td>
                            <td class="small muted">{{ $p->note }}</td>
                            <td>
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('panel.seo.redirects.approve', [$website, $p]) }}" class="inline-form" data-approve-form>
                                        @csrf
                                        <input class="control mono" type="text" name="to_path" value="{{ $p->to_path }}" style="max-width:260px">
                                        <select class="control" name="code" style="max-width:110px">@foreach (\App\Models\UrlRedirect::CODES as $code => $label)<option value="{{ $code }}" @selected($code === 301)>{{ $code }}</option>@endforeach</select>
                                        <button type="submit" name="decision" value="approve" class="btn btn--brand btn--pill">Onayla</button>
                                        <button type="submit" name="decision" value="reject" class="btn btn--ghost btn--pill" style="color:var(--danger)">Reddet</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">Bekleyen öneri yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    {{-- ---------------- 404 günlüğü ---------------- --}}
    @elseif ($tab === '404')
        <form method="GET" class="inline-form" style="margin-bottom:12px">
            <select class="control" name="durum" onchange="this.form.submit()">
                <option value="all" @selected($status === 'all')>Tümü</option>
                @foreach (\App\Models\NotFoundLog::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
            </select>
            <input class="control mono" type="search" name="q" value="{{ $q }}" placeholder="adres ara"><button type="submit" class="btn btn--ghost">Ara</button>
        </form>
        <div class="panel" style="padding:0">
            <table class="table">
                <thead><tr><th>URL</th><th>İlk görülme</th><th>Son görülme</th><th>Hit</th><th>Referer</th><th>Önerilen hedef</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="mono small">{{ $log->path }}</td>
                            <td class="small">{{ $log->first_seen_at->format('d.m.Y H:i') }}</td>
                            <td class="small">{{ $log->last_seen_at->format('d.m.Y H:i') }}</td>
                            <td class="mono"><b>{{ $log->hits }}</b></td>
                            <td class="small muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">{{ $log->referer ?: '—' }}</td>
                            <td class="mono small">{{ $log->suggested_path ?: '—' }}@if ($log->suggested_score !== null) <span class="badge {{ $log->suggested_score >= 60 ? 'badge--warn' : 'badge--muted' }}">%{{ $log->suggested_score }}</span>@endif</td>
                            <td><span class="badge {{ $log->status === 'redirected' ? 'badge--ok' : ($log->status === 'ignored' ? 'badge--muted' : 'badge--danger') }}">{{ \App\Models\NotFoundLog::STATUSES[$log->status] ?? $log->status }}</span></td>
                            <td>
                                @if ($canEdit && $log->status === 'open')
                                    <div class="row-actions">
                                        <form method="POST" action="{{ route('panel.seo.redirects.store', $website) }}">@csrf
                                            <input type="hidden" name="from_path" value="{{ $log->path }}"><input type="hidden" name="to_path" value="{{ $log->suggested_path ?: '/' }}"><input type="hidden" name="code" value="301"><input type="hidden" name="status" value="active"><input type="hidden" name="note" value="404 günlüğünden ({{ $log->hits }} isabet)">
                                            <button type="submit" class="btn btn--ghost btn--pill" @disabled(! $log->suggested_path)>Öneriye yönlendir</button>
                                        </form>
                                        <a href="{{ route('panel.seo.redirects.index', [$website, 'yonlendirmeler']) }}?from={{ urlencode($log->path) }}" class="btn btn--ghost btn--pill">Farklı hedef</a>
                                        <form method="POST" action="{{ route('panel.seo.redirects.404.status', [$website, $log->id]) }}">@csrf<input type="hidden" name="durum" value="ignore"><button type="submit" class="btn btn--ghost btn--pill">Yok say</button></form>
                                    </div>
                                @elseif ($canEdit && $log->status === 'ignored')
                                    <form method="POST" action="{{ route('panel.seo.redirects.404.status', [$website, $log->id]) }}">@csrf<input type="hidden" name="durum" value="open"><button type="submit" class="btn btn--ghost btn--pill">Yeniden aç</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Kayıt yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $logs->links() }}</div>

    {{-- ---------------- URL geçmişi ---------------- --}}
    @elseif ($tab === 'gecmis')
        <p class="body-muted" style="margin:0 0 12px;max-width:80ch">İçerik, hizmet ve lokasyon adreslerinin geçmişi: slug/üst sayfa değişimi ve silme. Silinen kaydın başlık/kategori/etiket anlık görüntüsü benzerlik eşleşmesinde kullanılır.</p>
        <div class="panel" style="padding:0">
            <table class="table">
                <thead><tr><th>Tarih</th><th>Varlık</th><th>Eski URL</th><th>Yeni URL</th><th>Neden</th><th>Anlık görüntü</th></tr></thead>
                <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td class="small">{{ $h->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="small">{{ $h->entity_type }} #{{ $h->entity_id }}</td>
                            <td class="mono small">{{ $h->old_path }}</td>
                            <td class="mono small">{{ $h->new_path ?: '— (silindi)' }}</td>
                            <td class="small">{{ \App\Models\ContentUrlHistory::REASONS[$h->reason] ?? $h->reason }}</td>
                            <td class="small muted">{{ $h->snapshot['title'] ?? '' }}{{ ! empty($h->snapshot['category']) ? ' · '.$h->snapshot['category'] : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">Geçmiş kaydı yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    {{-- ---------------- Kırık URL botu ---------------- --}}
    @elseif ($tab === 'bot')
        <div class="panel" style="margin-bottom:18px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <div>
                <p class="label" style="margin-bottom:4px">Kırık URL / Redirect Botu</p>
                <p class="body-muted" style="margin:0;max-width:70ch">404 veren adresler, yönlendirilmemiş eski adresler, kırık iç/dış bağlantılar, yanlış hedef, redirect chain/loop, ana sayfaya yönlendirmeler, sitemap ve canonical uyumsuzlukları.</p>
                @if ($scan)<p class="small muted" style="margin:6px 0 0">Son tarama: {{ \Illuminate\Support\Carbon::parse($scan['ran_at'])->format('d.m.Y H:i') }} · {{ $scan['external'] ? $scan['probed'].' dış bağlantı yoklandı' : 'dış bağlantılar yoklanmadı' }}</p>@endif
            </div>
            @if ($canAudit)
                <form method="POST" action="{{ route('panel.seo.redirects.scan', $website) }}" class="inline-form" data-scan-form>
                    @csrf
                    <label class="checkbox-row"><input type="checkbox" name="external" value="1"><span class="small">Dış bağlantıları da yokla (en fazla {{ \App\Services\RedirectService::MAX_EXTERNAL_PROBES }})</span></label>
                    <button type="submit" class="btn btn--brand">Taramayı çalıştır</button>
                </form>
            @endif
        </div>

        @if ($scan)
            <div class="kpis" style="margin-bottom:14px">
                @foreach ($levelLabel as $lvl => $lbl)
                    <div class="kpi {{ $lvl === 'critical' && $scan['counts'][$lvl] > 0 ? 'watch' : ($lvl === 'fixed' ? 'ok' : '') }}"><span class="k">{{ $lbl }}</span><span class="v">{{ $scan['counts'][$lvl] }}</span></div>
                @endforeach
            </div>
            <div class="panel" style="padding:0">
                <table class="table">
                    <thead><tr><th>Seviye</th><th>Bulgu</th><th>Ayrıntı</th><th>Öneri</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($scan['findings'] as $f)
                            <tr>
                                <td><span class="badge {{ $levelClass[$f['level']] }}">{{ $levelLabel[$f['level']] }}</span></td>
                                <td class="small">{{ $f['title'] }}</td>
                                <td class="small muted">{{ $f['detail'] }}</td>
                                <td class="mono small">@if ($f['suggestion'])<span>{{ $f['suggestion']['path'] }}</span> <span class="badge {{ $f['suggestion']['score'] >= 85 ? 'badge--ok' : ($f['suggestion']['score'] >= 60 ? 'badge--warn' : 'badge--muted') }}">%{{ $f['suggestion']['score'] }}</span>@else — @endif</td>
                                <td>
                                    @if ($canEdit && $f['path'] !== null && $f['suggestion'] && in_array($f['kind'], ['404', 'history', 'broken_target', 'to_home', 'internal_link'], true))
                                        <form method="POST" action="{{ route('panel.seo.redirects.store', $website) }}">@csrf
                                            <input type="hidden" name="from_path" value="{{ $f['path'] }}"><input type="hidden" name="to_path" value="{{ $f['suggestion']['path'] }}"><input type="hidden" name="code" value="301"><input type="hidden" name="status" value="active"><input type="hidden" name="note" value="Bot: {{ $f['kind'] }}">
                                            <button type="submit" class="btn btn--ghost btn--pill">Yönlendir</button>
                                        </form>
                                    @elseif ($canEdit && $f['fix'] === 'flatten')
                                        @php($rid = $redirectMap[$f['path']]['id'] ?? null)
                                        @if ($rid)<form method="POST" action="{{ route('panel.seo.redirects.flatten', [$website, $rid]) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Düzleştir</button></form>@endif
                                    @elseif ($canEdit && $f['fix'] === 'delete')
                                        @php($rid = $redirectMap[$f['path']]['id'] ?? null)
                                        @if ($rid)<form method="POST" action="{{ route('panel.seo.redirects.destroy', [$website, $rid]) }}">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Kaydı sil</button></form>@endif
                                    @elseif ($f['fix'] === 'approve')
                                        <a href="{{ route('panel.seo.redirects.index', [$website, 'oneriler']) }}" class="btn btn--ghost btn--pill">Önerilere git</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">Bulgu yok — temiz.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty-state">Henüz tarama çalıştırılmadı.</div>
        @endif
    @endif

    @if ($top->isNotEmpty() && $tab === 'yonlendirmeler')
        <div class="panel" style="margin-top:18px">
            <p class="label" style="margin-bottom:8px">En çok isabet alan yönlendirmeler</p>
            <ul class="stack" style="gap:4px;margin:0;padding:0;list-style:none">
                @foreach ($top as $r)<li class="mono small">{{ $r->hits }} × {{ $r->from_path }} → {{ $r->to_path }}</li>@endforeach
            </ul>
        </div>
    @endif
@endsection
