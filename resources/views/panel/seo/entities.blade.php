@extends('layouts.panel')

@section('title', 'Entity / Knowledge Graph — '.$website->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.seo.center', $website) }}">Command Center</a> · {{ $website->name }}</p>
            <h1 class="h2">Entity / Knowledge Graph</h1>
            <p>Sitenin varlıkları ve aralarındaki ilişkiler: Marka → Hizmetler ↔ Lokasyonlar, Yazı → Hizmet/Lokasyon, Konu → Varlık. Şema (Organization, LocalBusiness, Service, Article), llms.txt ve iç bağlantılar bu graftan beslenir. Yalnız veritabanındaki kayıtlar listelenir.</p>
        </div>
        <div class="panel-head__actions">
            <a href="{{ route('panel.seo.settings.show', [$website, 'varlik']) }}" class="btn btn--ghost btn--pill">Organization kimliği (Entity ayarları)</a>
            <a href="{{ route('panel.seo.geo', $website) }}" class="btn btn--brand btn--pill">GEO Manager</a>
        </div>
    </div>

    <div class="kpis" style="margin-bottom:18px">
        <div class="kpi"><span class="k">Hizmet</span><span class="v">{{ count($graph['services']) }}</span><span class="d">{{ collect($graph['services'])->where('filled', '>', 0)->count() }} cevaplı</span></div>
        <div class="kpi"><span class="k">Lokasyon (yayında)</span><span class="v">{{ count($graph['locations']) }}</span><span class="d">Service ↔ Location pivot</span></div>
        <div class="kpi"><span class="k">Yazar / SSS</span><span class="v">{{ count($graph['authors']) }} / {{ $graph['faq_count'] }}</span><span class="d">Person · Question düğümleri</span></div>
        <div class="kpi {{ $graph['unlinked_posts']->isNotEmpty() ? 'watch' : 'ok' }}"><span class="k">İlişkisiz yazı</span><span class="v">{{ $graph['unlinked_posts']->count() }}</span><span class="d">{{ $graph['posts']->count() }} yayındaki yazıdan</span></div>
    </div>

    <div class="grid-auto" style="--min:320px;--gap:18px;align-items:start">
        <div class="stack" style="gap:18px">
            <div class="panel">
                <p class="eyebrow">Marka / Organization</p>
                <dl class="dl">
                    <dt>Ad</dt><dd>{{ $graph['brand']['name'] }}</dd>
                    <dt>Tüzel ad</dt><dd>{{ $graph['brand']['legal_name'] ?: '—' }}</dd>
                    <dt>Telefon / e-posta</dt><dd>{{ $graph['brand']['phone'] ?: '—' }} · {{ $graph['brand']['email'] ?: '—' }}</dd>
                    <dt>Adres</dt><dd>{{ $graph['brand']['address'] ?: '—' }}</dd>
                    <dt>sameAs</dt><dd class="small mono">{{ implode(', ', $graph['brand']['same_as']) ?: '—' }}</dd>
                    <dt>Tür / kuruluş</dt><dd>{{ $settings['entity.org_type'] }} · {{ $settings['entity.founding_date'] ?: '—' }}</dd>
                </dl>
            </div>

            <div class="panel">
                <p class="eyebrow">Hizmetler → lokasyonlar · cevaplar · yazılar</p>
                @if ($graph['services'] === [])<p class="body-muted" style="margin:0">Hizmet yok.</p>@else
                    <table class="data">
                        <thead><tr><th>Hizmet</th><th>Sunulduğu şubeler</th><th>GEO cevap</th><th>Yazı</th></tr></thead>
                        <tbody>
                            @foreach ($graph['services'] as $row)
                                <tr>
                                    <td><a href="{{ route('panel.services.edit', $row['model']) }}">{{ $row['model']->name }}</a></td>
                                    <td class="small">{{ implode(', ', $row['locations']) ?: '—' }}</td>
                                    <td><span class="badge badge--{{ $row['filled'] >= 6 ? 'ok' : ($row['filled'] > 0 ? 'warn' : 'danger') }}">{{ $row['filled'] }}/13</span></td>
                                    <td class="mono">{{ $row['articles'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="panel">
                <p class="eyebrow">Lokasyonlar → hizmetler · yazılar</p>
                @if ($graph['locations'] === [])<p class="body-muted" style="margin:0">Yayında şube yok.</p>@else
                    <table class="data">
                        <thead><tr><th>Şube</th><th>Hizmetler</th><th>Yazı</th></tr></thead>
                        <tbody>
                            @foreach ($graph['locations'] as $row)
                                <tr><td><a href="{{ route('panel.geo.edit', $row['model']) }}">{{ $row['model']->name }}</a> <span class="small muted">{{ $row['model']->city }}</span></td><td class="small">{{ implode(', ', $row['services']) ?: '—' }}</td><td class="mono">{{ $row['articles'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($graph['authors'] !== [])
                <div class="panel">
                    <p class="eyebrow">Yazarlar (Person)</p>
                    <ul style="margin:0;padding-left:18px">@foreach ($graph['authors'] as $a)<li>{{ $a['name'] }} <span class="small muted">· {{ $a['posts'] }} yazı</span></li>@endforeach</ul>
                </div>
            @endif
        </div>

        <div class="stack" style="gap:18px">
            <div class="panel">
                <p class="eyebrow">Yazı → hizmet / lokasyon</p>
                <form method="GET" action="{{ route('panel.seo.entities', $website) }}" class="inline-form" style="margin-bottom:12px">
                    <select class="control" name="yazi" style="flex:1">
                        <option value="">— yazı ya da sayfa seçin —</option>
                        @foreach ($graph['posts'] as $post)<option value="{{ $post->id }}" @selected($selected?->id === $post->id)>{{ $post->title }}@if (! isset($graph['by_content'][$post->id])) · ilişkisiz @endif</option>@endforeach
                        @foreach ($pages as $p)<option value="{{ $p->id }}" @selected($selected?->id === $p->id)>Sayfa: {{ $p->title }}</option>@endforeach
                    </select>
                    <button type="submit" class="btn btn--ghost btn--pill">Aç</button>
                </form>
                @if ($selected !== null)
                    @php($currentServices = collect($selectedRelations)->where('to_type', 'service')->pluck('to_id')->map(fn ($v) => (int) $v)->all())
                    @php($currentLocations = collect($selectedRelations)->where('to_type', 'location')->pluck('to_id')->map(fn ($v) => (int) $v)->all())
                    <form method="POST" action="{{ route('panel.seo.entities.relations', $website) }}" class="stack" style="gap:10px">
                        @csrf
                        <input type="hidden" name="content_id" value="{{ $selected->id }}">
                        <div><strong>{{ $selected->title }}</strong> <span class="mono small muted">{{ $selected->path() }}</span></div>
                        <div class="grid-auto" style="--min:200px;--gap:10px">
                            <div><p class="label" style="margin:0 0 6px">Hakkında olduğu hizmetler</p>
                                @forelse ($serviceOptions as $s)<label class="checkbox-row"><input type="checkbox" name="services[]" value="{{ $s->id }}" @checked(in_array($s->id, $currentServices, true)) @disabled(! $canEdit)><span>{{ $s->name }}</span></label>@empty<span class="small muted">—</span>@endforelse
                            </div>
                            <div><p class="label" style="margin:0 0 6px">İlgili lokasyonlar</p>
                                @forelse ($locationOptions as $l)<label class="checkbox-row"><input type="checkbox" name="locations[]" value="{{ $l->id }}" @checked(in_array($l->id, $currentLocations, true)) @disabled(! $canEdit)><span>{{ $l->name }} <span class="small muted">{{ $l->city }}</span></span></label>@empty<span class="small muted">—</span>@endforelse
                            </div>
                        </div>
                        @if ($canEdit)<div><button type="submit" class="btn btn--brand">İlişkileri kaydet</button></div>@else<span class="small muted">Düzenleme için seo.edit gerekir.</span>@endif
                    </form>
                @endif
            </div>

            <div class="panel">
                <p class="eyebrow">Konu → varlık</p>
                @if ($canEdit)
                    <form method="POST" action="{{ route('panel.seo.entities.topic', $website) }}" class="grid-auto" style="--min:160px;--gap:8px;margin-bottom:12px">
                        @csrf
                        <input class="control" type="text" name="topic" maxlength="80" placeholder="konu (örn. sirket-kurulusu)" required>
                        <select class="control" name="to_type"><option value="service">Hizmet</option><option value="location">Lokasyon</option><option value="content">Yazı</option></select>
                        <input class="control mono" type="number" name="to_id" placeholder="kimlik" required min="1">
                        <button type="submit" class="btn btn--ghost btn--pill">Bağla</button>
                    </form>
                @endif
                @if ($graph['topics'] === [])<p class="body-muted small" style="margin:0">Konu ilişkisi yok. Anahtar kelime kümeleri (Keyword Intelligence) burada varlığa bağlanır.</p>@else
                    <ul style="margin:0;padding-left:18px">
                        @foreach ($graph['topics'] as $topic => $rows)
                            <li><strong>{{ $topic }}</strong> → @foreach ($rows as $r)<span class="badge badge--info">{{ $graph['labels'][$r->to_type.':'.$r->to_id] ?? ($r->to_type.' #'.$r->to_id) }}@if ($canEdit) <form method="POST" action="{{ route('panel.seo.entities.unlink', [$website, $r->id]) }}" style="display:inline">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost" style="padding:0 4px;border:0" title="Kaldır">×</button></form>@endif</span> @endforeach</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="panel">
                <p class="eyebrow">Tüm ilişkiler ({{ $graph['relations']->count() }})</p>
                @if ($graph['relations']->isEmpty())<p class="body-muted small" style="margin:0">Henüz ilişki yok. İlişkisiz yazılar: {{ $graph['unlinked_posts']->pluck('title')->take(8)->implode(', ') ?: '—' }}</p>@else
                    <table class="data">
                        <thead><tr><th>Kaynak</th><th>İlişki</th><th>Hedef</th></tr></thead>
                        <tbody>
                            @foreach ($graph['relations'] as $r)
                                <tr><td class="small">{{ $r->from_type === 'topic' ? 'Konu: '.$r->from_id : ($graph['labels'][$r->from_type.':'.$r->from_id] ?? $r->from_type.' #'.$r->from_id) }}</td><td class="small muted">{{ \App\Models\EntityRelation::RELATIONS[$r->relation] ?? $r->relation }}</td><td class="small">{{ $graph['labels'][$r->to_type.':'.$r->to_id] ?? $r->to_type.' #'.$r->to_id }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
@endsection
