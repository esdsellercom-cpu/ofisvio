@extends('layouts.panel')

@section('title', 'Organizasyon seçimi')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow">Organizasyon</p>
            <h1 class="h2">{{ $isStaff ? 'Hangi müşteriye gireceksiniz?' : 'Hangi organizasyonla devam edeceksiniz?' }}</h1>
        </div>
        @can('user.manage')
            <div class="panel-head__actions">
                <a href="{{ route('panel.onboarding.create') }}" class="btn btn--brand">Yeni müşteri organizasyonu</a>
            </div>
        @endcan
    </div>

    @if ($organizations->isEmpty())
        <div class="empty-state">
            @if ($isStaff)
                Henüz müşteri organizasyonu yok.
                @can('user.manage') İlkini "Yeni müşteri organizasyonu" ile açın. @endcan
            @else
                Hesabınız henüz bir organizasyona bağlı değil. Sizi davet eden kişiyle ya da Ofisvio ile iletişime geçin.
            @endif
        </div>
    @else
        @if ($isStaff)
            <p class="body-muted" style="margin:0 0 18px">
                Personel girişi denetim kaydına yazılır: hangi müşteriye, ne zaman, hangi IP'den girdiğiniz saklanır.
            </p>
        @endif

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Organizasyon</th>
                        @if ($showsQueue)
                            <th class="num">Bekleyen KYC</th>
                        @endif
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($organizations as $organization)
                        <tr>
                            <td>
                                {{ $organization->name }}
                                @if ($activeId === $organization->id)
                                    <span class="badge badge--ok" style="margin-left:8px">Aktif</span>
                                @endif
                            </td>
                            @if ($showsQueue)
                                <td class="num">
                                    @php($pending = $pendingCounts[$organization->id] ?? 0)
                                    @if ($pending > 0)
                                        <span class="badge badge--warn">{{ $pending }} belge</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            @endif
                            <td>
                                <form method="POST" action="{{ route('panel.context.switch') }}" class="row-actions">
                                    @csrf
                                    <input type="hidden" name="organization_id" value="{{ $organization->id }}">
                                    <button type="submit" class="btn btn--ghost btn--pill">
                                        {{ $activeId === $organization->id ? 'Devam et' : 'Gir' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
