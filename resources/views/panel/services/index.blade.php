@extends('layouts.panel')

@section('title', 'Hizmetler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Hizmetler modülü · vitrin kartları, lokasyon etiketleri, teklif formu, hizmet sayfaları tek kaynak</p>
            <h1 class="h2">Hizmetler</h1>
        </div>
        <div class="panel-head__actions">
            @can('service.manage')<a href="{{ route('panel.services.create') }}" class="btn btn--brand">Yeni hizmet</a>@endcan
        </div>
    </div>

    @error('service')<div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Sıra</th><th>Hizmet</th><th>Özet</th><th>Fiyat metni</th><th>Rezervasyon</th><th class="num">Lokasyon</th><th>Durum</th><th></th></tr></thead>
            <tbody>
                @forelse ($services as $s)
                    <tr>
                        <td class="mono small">{{ $s->sort_order }}</td>
                        <td><strong>{{ $s->name }}</strong>@if ($s->is_flagship) <span class="badge badge--ok">amiral</span>@endif<span class="small muted mono" style="display:block">/cozum/{{ $s->slug }}</span></td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($s->summary ?? '', 80) }}</td>
                        <td class="mono small">{{ $s->price_text ?? '—' }}</td>
                        <td class="small">{{ $s->booking_kind ? \App\Models\Service::BOOKING_KINDS[$s->booking_kind] : '—' }}</td>
                        <td class="num mono">{{ $s->locations_count }}</td>
                        <td><span class="badge badge--{{ $s->is_active ? 'ok' : 'muted' }}">{{ $s->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                        <td>
                            <div class="row-actions">
                                <a href="{{ route('site.service', $s->slug) }}" target="_blank" rel="noopener" class="btn btn--ghost btn--pill">Vitrin</a>
                                @can('service.manage')
                                    <a href="{{ route('panel.services.edit', $s) }}" class="btn btn--ghost btn--pill">Düzenle</a>
                                    <form method="POST" action="{{ route('panel.services.destroy', $s) }}" onsubmit="return confirm('Hizmet silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger)">Sil</button></form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Henüz hizmet yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection