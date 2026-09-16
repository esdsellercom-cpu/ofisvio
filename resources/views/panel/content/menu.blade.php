@extends('layouts.panel')

@section('title', 'Menü — '.$website->name)

@php
    use App\Enums\ContentStatus;
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ $backUrl }}">{{ $backLabel }}</a> · {{ $website->name }}@if ($website->domain) · {{ $website->domain }}@endif</p>
            <h1 class="h2">Site menüsü</h1>
        </div>
        <div class="panel-head__actions">
            <a href="{{ $website->baseUrl() }}" class="btn btn--ghost" target="_blank" rel="noopener">Siteyi aç ↗</a>
        </div>
    </div>

    <p class="body-muted" style="margin:0 0 18px;max-width:72ch">
        Menüde yalnızca <strong>yayındaki</strong> sayfalar görünür; sıra numarası küçükten büyüğe, boş bırakılanlar en sonda alfabetik.
        Taslak/zamanlanmış sayfalar yayına girince buradaki ayarla menüye düşer. "Yazılar" bağlantısı yayında yazı varsa otomatik eklenir.
    </p>

    @isset($themeAction)
        <form method="POST" action="{{ $themeAction }}" class="panel inline-form" style="margin-bottom:20px;align-items:end">
            @csrf @method('PUT')
            <label class="field" style="flex:1 1 320px"><span class="label">Tema</span>
                <select class="control" name="theme" @error('theme') aria-invalid="true" @enderror>
                    @foreach (config('ofisvio.themes') as $key => $label)
                        <option value="{{ $key }}" @selected(old('theme', $website->theme) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="btn btn--ghost">Temayı uygula</button>
        </form>
    @endisset
    @isset($settingsAction)
        <form method="POST" action="{{ $settingsAction }}" class="panel stack" style="margin-bottom:20px;gap:12px">
            @csrf @method('PUT')
            <p class="eyebrow" style="margin:0">Site genel ayarları — iletişim ve kimlik</p>
            @include('panel.websites.partials.settings-fields', ['site' => $website])
            <div><button type="submit" class="btn btn--ghost">Ayarları kaydet</button></div>
        </form>
    @endisset
    @isset($linksAction)
        <form method="POST" action="{{ $linksAction }}" class="panel stack" style="margin-bottom:20px;gap:10px">
            @csrf @method('PUT')
            <label class="field"><span class="label">Sayfa dışı bağlantılar — satır başına <code>Etiket | URL</code> (https://, mailto:, tel: ya da /yol; en fazla 8)</span>
                <textarea class="control mono" name="links" style="min-height:88px" placeholder="Randevu | https://calendly.com/acme&#10;Bize yazın | mailto:info@acme.com" @error('links') aria-invalid="true" @enderror>{{ old('links', collect($website->nav_links ?? [])->map(fn ($l) => $l['label'].' | '.$l['url'])->implode("\n")) }}</textarea>
            </label>
            @error('links')<p class="small" style="color:var(--danger);margin:0">{{ $message }}</p>@enderror
            <div><button type="submit" class="btn btn--ghost">Bağlantıları kaydet</button></div>
        </form>
    @endisset
    @if ($pages->isEmpty())
        <div class="empty-state">Bu sitede sayfa yok.</div>
    @else
        <form method="POST" action="{{ $formAction }}">
            @csrf @method('PUT')
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th style="width:90px">Sıra</th><th style="width:110px">Menüde</th><th>Sayfa</th><th>Durum</th></tr></thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr>
                                <td><input class="control mono" type="number" name="nav[{{ $page->id }}][order]" value="{{ old("nav.{$page->id}.order", $page->nav_order) }}" min="1" max="999" style="width:80px" aria-label="{{ $page->title }} sırası"></td>
                                <td>
                                    <input type="hidden" name="nav[{{ $page->id }}][show]" value="0">
                                    <label class="checkbox-row" style="margin:0"><input type="checkbox" name="nav[{{ $page->id }}][show]" value="1" @checked(old("nav.{$page->id}.show", $page->show_in_nav))><span>Göster</span></label>
                                </td>
                                <td>{{ $page->title }}<span class="small muted mono" style="display:block;font-weight:400">{{ $page->path() }}</span></td>
                                <td><span class="badge badge--{{ $page->status === ContentStatus::PUBLISHED ? 'ok' : 'muted' }}">{{ $page->status->label() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px">
                <button type="submit" class="btn btn--brand">Menüyü kaydet</button>
                <a href="{{ $backUrl }}" class="btn btn--ghost">Vazgeç</a>
            </div>
        </form>
    @endif
@endsection
