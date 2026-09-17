@extends('layouts.panel')

@section('title', $user->name)

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.users.index') }}">Kullanıcılar</a></p>
            <h1 class="h2">{{ $user->name }}</h1>
        </div>
        <div class="panel-head__actions">
            @if ($user->isSuspended())<span class="badge badge--danger">Askıda</span>@endif
            @if ($user->hasConfirmedTwoFactor())<span class="badge badge--ok">2FA açık</span>@else<span class="badge badge--warn">2FA yok</span>@endif
            <form method="POST" action="{{ route('panel.users.resend', $user) }}">@csrf
                <button type="submit" class="btn btn--ghost">Şifre bağlantısı gönder</button>
            </form>
        </div>
    </div>

    @error('status')
        <div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>
    @enderror
    @error('reason')
        <div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>
    @enderror
    @error('role')
        <div class="notice notice--error" role="alert" style="margin-bottom:22px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>
    @enderror

    <div class="grid-auto" style="--min:320px;--gap:20px;align-items:start">
        <div class="stack" style="gap:20px">
            <div class="panel">
                <p class="eyebrow">Künye</p>
                <dl class="dl">
                    <dt>E-posta</dt><dd class="mono">{{ $user->email }}</dd>
                    <dt>Kayıt</dt><dd>{{ $user->created_at?->format('d.m.Y H:i') }}</dd>
                    <dt>Organizasyonlar</dt><dd>{{ $user->organizationMemberships->map(fn ($m) => $m->organization?->name)->filter()->implode(', ') ?: '—' }}</dd>
                </dl>
            </div>

            <div class="panel">
                <p class="eyebrow">Roller</p>
                <table class="data">
                    <thead><tr><th>Rol</th><th>Kapsam</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($user->userRoles as $r)
                            @php($global = $r->company_id === null && $r->organization_id === null && $r->location_id === null)
                            <tr>
                                <td>{{ __('roles.'.$r->role->name) }} <span class="mono small muted">{{ $r->role->name }}</span></td>
                                <td class="small">
                                    @if ($global) Global (personel)
                                    @elseif ($r->company_id) Şirket #{{ $r->company_id }}
                                    @elseif ($r->organization_id) Organizasyon #{{ $r->organization_id }}
                                    @else Lokasyon: {{ $locations->firstWhere('id', $r->location_id)?->name ?? '#'.$r->location_id }}
                                    @endif
                                </td>
                                <td><span class="badge badge--{{ $r->status === 'active' ? 'ok' : 'muted' }}">{{ $r->status === 'active' ? 'Etkin' : 'Askıda' }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        @if ($global)
                                            @if ($r->status === 'active')
                                                <form method="POST" action="{{ route('panel.users.roles.suspend', [$user, $r]) }}">@csrf
                                                    <button type="submit" class="btn btn--ghost btn--pill" style="color:var(--danger);border-color:#E9C4BC">Askıya al</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('panel.users.roles.reactivate', [$user, $r]) }}">@csrf
                                                    <button type="submit" class="btn btn--ghost btn--pill">Etkinleştir</button>
                                                </form>
                                            @endif
                                        @else
                                            <span class="small muted">şirket/organizasyon panelinden</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">Rol yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <p class="eyebrow">Personel rolü ata</p>
            <form method="POST" action="{{ route('panel.users.roles.assign', $user) }}" class="stack" style="gap:12px">
                @csrf
                <label class="field"><span class="label">Rol</span>
                    <select class="control" name="role" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}">{{ __('roles.'.$role->name) }} ({{ $role->name }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="field"><span class="label">Şube (yalnız lokasyon kapsamlı roller: {{ implode(', ', array_map(fn ($r) => __('roles.'.$r), $locationRoles)) }})</span>
                    <select class="control" name="location_id">
                        <option value="">— global rol —</option>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((string) old('location_id') === (string) $loc->id)>{{ $loc->name }} ({{ $loc->city }})</option>
                        @endforeach
                    </select>
                    @error('location_id')<span class="small" style="color:var(--danger)">{{ $message }}</span>@enderror
                </label>
                <div><button type="submit" class="btn btn--brand">Ata</button></div>
            </form>
            <p class="small muted" style="margin:14px 0 0">Müşteri rolleri (sahip, şirket yöneticisi…) şirket sayfasındaki Üyeler ekranından verilir.</p>
        </div>
    </div>
    {{-- Hesap durumu, oturumlar, giriş geçmişi (audit: user status, active sessions, login history) --}}
    <div class="grid g3" style="margin-top:20px">
        <div class="card">
            <div class="card__head"><h3>Hesap durumu</h3></div>
            <div class="card__body">
                @if ($user->isSuspended())
                    <p class="small" style="margin:0 0 10px"><span class="pill c">Askıda</span> {{ $user->suspended_at?->format('d.m.Y H:i') }} — {{ $user->suspended_reason }}</p>
                    <form method="POST" action="{{ route('panel.users.reactivate', $user) }}">@csrf<button type="submit" class="btn btn--brand">Yeniden etkinleştir</button></form>
                @elseif ($user->id === auth()->id())
                    <p class="small muted" style="margin:0">Kendi hesabınızı askıya alamazsınız.</p>
                @else
                    <form method="POST" action="{{ route('panel.users.suspend', $user) }}" class="stack" style="gap:8px" onsubmit="return confirm('Hesap askıya alınsın mı? Açık oturumları kapatılır, giriş engellenir.')">
                        @csrf
                        <label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="reason" minlength="5" maxlength="300" required></label>
                        <div><button type="submit" class="btn btn--danger">Hesabı askıya al</button></div>
                    </form>
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h3>Açık oturumlar</h3>@if ($sessions !== null)<span class="sub">{{ count($sessions) }}</span>@endif
                @if ($sessions !== null && $sessions !== [])<span class="r"><form method="POST" action="{{ route('panel.users.sessions.terminate', $user) }}">@csrf<button type="submit" class="btn btn--ghost btn--pill">Tümünü kapat</button></form></span>@endif
            </div>
            @if ($sessions === null)
                <div class="card__body"><p class="small muted" style="margin:0">Yalnız veritabanı oturum sürücüsünde.</p></div>
            @elseif ($sessions === [])
                <div class="empty-state" style="border:0">Açık oturum yok.</div>
            @else
                <div class="rows">@foreach ($sessions as $s)<div class="row"><div class="main-t"><b>{{ $s['ip'] ?? '—' }}</b><span>{{ Str::limit($s['user_agent'] ?? '—', 70) }}</span></div><span class="rt mini">{{ $s['last_activity']->diffForHumans() }}</span></div>@endforeach</div>
            @endif
        </div>
        <div class="card">
            <div class="card__head"><h3>Giriş geçmişi</h3><span class="sub">Son 20</span></div>
            @if ($loginHistory->isEmpty())
                <div class="empty-state" style="border:0">Kayıt yok.</div>
            @else
                <div class="rows">@foreach ($loginHistory as $e)<div class="row"><span class="dotmark" style="background:{{ in_array($e->event, ['failed', 'lockout'], true) ? 'var(--crit)' : 'var(--good)' }}" aria-hidden="true"></span><div class="main-t"><b>{{ $e->label() }}</b><span>{{ $e->ip ?? '—' }}</span></div><span class="rt mini">{{ $e->created_at->format('d.m.Y H:i') }}</span></div>@endforeach</div>
            @endif
        </div>
    </div>
@endsection
