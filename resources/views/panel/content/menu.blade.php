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
