@extends('layouts.panel')

@section('title', 'Gelen bildirimler')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Uygulama içi · {{ $unread }} okunmamış</p>
            <h1 class="h2">Bildirimler</h1>
        </div>
        <div class="panel-head__actions">
            @if ($unread > 0)
                <form method="POST" action="{{ route('panel.notifications.inbox.read') }}">@csrf<button type="submit" class="btn btn--ghost">Tümünü okundu işaretle</button></form>
            @endif
        </div>
    </div>

    <div class="stack" style="gap:10px">
        @forelse ($notifications as $n)
            @php($d = $n->data)
            <div class="card" style="padding:16px 18px;display:block;border-color:{{ $n->read_at ? 'var(--line)' : 'var(--brand)' }}">
                <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                    <strong>{{ $d['title'] ?? 'Bildirim' }}</strong>
                    <span class="small muted mono">{{ $n->created_at->format('d.m.Y H:i') }}</span>
                </div>
                <pre class="small" style="white-space:pre-wrap;margin:8px 0 0;font-family:inherit">{{ $d['body'] ?? '' }}</pre>
                <div class="row-actions" style="margin-top:10px">
                    @if (! empty($d['url']))
                        <a href="{{ $d['url'] }}" class="btn btn--ghost btn--pill">Kaydı aç</a>
                    @endif
                    @unless ($n->read_at)
                        <form method="POST" action="{{ route('panel.notifications.inbox.read') }}">@csrf<input type="hidden" name="id" value="{{ $n->id }}"><button type="submit" class="btn btn--ghost btn--pill">Okundu</button></form>
                    @endunless
                </div>
            </div>
        @empty
            <p class="muted">Bildirim yok.</p>
        @endforelse
    </div>
    <div style="margin-top:16px">{{ $notifications->links() }}</div>
@endsection
