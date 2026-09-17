@extends('layouts.site')

@section('title', $event->title.' — '.config('ofisvio.brand.name'))
@section('description', $event->summary ?? $event->title.' · '.$event->starts_at->format('d.m.Y H:i'))

@section('content')
    <article class="wrap section" style="padding-top:64px">
        <p class="eyebrow"><a href="{{ route('site.events') }}">Etkinlikler</a></p>
        <h1 class="h1" style="font-size:clamp(34px,5vw,56px)">{{ $event->title }}</h1>
        <p class="lede" style="margin:18px 0 0;max-width:60ch">{{ $event->starts_at->format('d.m.Y H:i') }} – {{ $event->ends_at->format($event->ends_at->isSameDay($event->starts_at) ? 'H:i' : 'd.m.Y H:i') }} · {{ $event->location?->name ?? 'Çevrimiçi' }}@if ($event->room) · {{ $event->room->name }}@endif · {{ $event->price > 0 ? money($event->price) : 'Ücretsiz' }}</p>

        <div class="grid-auto" style="--min:300px;--gap:32px;margin-top:36px;align-items:start">
            <div>
                @if ($event->cover)
                    <figure style="margin:0 0 24px;border-radius:var(--r-lg);overflow:hidden;border:1px solid var(--line)">@include('site.partials.picture', ['media' => $event->cover, 'sizes' => '(max-width: 700px) 100vw, 60vw', 'eager' => true, 'style' => 'width:100%;aspect-ratio:16/10;object-fit:cover;display:block'])</figure>
                @endif
                @if ($event->summary)<p class="lede">{{ $event->summary }}</p>@endif
                @if ($event->description)<div class="prose">{!! $event->renderedDescription() !!}</div>@endif
            </div>

            <aside class="panel" id="kayit">
                <p class="eyebrow">Kayıt</p>
                @if (session('event_registered'))
                    <div class="notice"><span class="notice__dot"></span><div>Kaydınız alındı. Etkinlik bilgileri e-posta adresinize gönderilecek.</div></div>
                @elseif (! $event->registration_open || $event->starts_at->isPast())
                    <p class="body-muted">Bu etkinliğe kayıt kapalı.</p>
                @elseif ($event->isFull())
                    <p class="body-muted">Kontenjan doldu.</p>
                @else
                    @if ($event->capacity)<p class="small muted">Kalan kontenjan: {{ max(0, $event->capacity - $event->activeRegistrations()) }}</p>@endif
                    @if ($errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot"></span><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
                    <form method="POST" action="{{ route('site.event.register', $event->slug) }}" class="stack" style="gap:12px;margin-top:12px">
                        @csrf
                        <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                        <label class="field"><span class="label">Ad soyad</span><input class="control" type="text" name="name" value="{{ old('name') }}" required maxlength="120"></label>
                        <label class="field"><span class="label">E-posta</span><input class="control" type="email" name="email" value="{{ old('email') }}" required maxlength="190"></label>
                        <label class="field"><span class="label">Telefon</span><input class="control" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32"></label>
                        <label class="field"><span class="label">Şirket</span><input class="control" type="text" name="company_name" value="{{ old('company_name') }}" maxlength="160"></label>
                        <label class="checkbox-row"><input type="checkbox" name="kvkk" value="1" required @checked(old('kvkk'))><span><a href="{{ $kvkkUrl }}" style="color:var(--brand);font-weight:600">KVKK</a> aydınlatma metnini okudum, iletişim kurulmasını onaylıyorum.</span></label>
                        <button type="submit" class="btn btn--brand btn--block">Kayıt ol</button>
                    </form>
                @endif
            </aside>
        </div>
    </article>
@endsection
