@extends('layouts.panel')

@section('title', $company->legal_name)

@section('content')
    @php($currentStatus = $company->status)
    @php($journeyIndex = collect($journey)->search(fn ($step) => in_array($currentStatus, $step['statuses'], true)))

    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.index') }}">Şirketler</a> / {{ $activeOrganization->name }}</p>
            <h1 class="h2">{{ $company->legal_name }}</h1>
        </div>
        <div class="panel-head__actions">
            @include('panel.partials.company-status', ['status' => $currentStatus])
            @can('membership.manage', $company)
                <a href="{{ route('panel.companies.members.index', $company) }}" class="btn btn--ghost">Üyeler</a>
            @endcan
            @canany(['kyc.view', 'kyc.view_status'], $company)
                <a href="{{ route('panel.companies.kyc.show', $company) }}" class="btn btn--brand">KYC belgeleri</a>
            @endcanany
        </div>
    </div>

    <div class="grid-auto" style="--min:300px;--gap:20px;align-items:start">
        <div class="panel">
            <p class="eyebrow">Künye</p>
            <dl class="dl">
                <dt>Unvan</dt><dd>{{ $company->legal_name }}</dd>
                <dt>Vergi no</dt><dd class="mono">{{ $company->tax_number ?: '—' }}</dd>
                <dt>Kayıt</dt><dd>{{ $company->created_at?->format('d.m.Y H:i') }}</dd>
                <dt>KYC</dt>
                <dd>
                    @if ($kyc['complete'])
                        <span class="badge badge--ok">Zorunlu belgeler onaylı</span>
                    @else
                        <span class="badge badge--warn">{{ count($kyc['missing']) }} zorunlu belge eksik</span>
                    @endif
                </dd>
            </dl>
        </div>

        <div class="panel">
            <p class="eyebrow">Aktivasyon süreci</p>
            @if ($journeyIndex === false)
                <p class="body-muted" style="margin:0">
                    Şirket şu an <strong>{{ $currentStatus->label() }}</strong> durumunda; bu durum standart akışın dışındadır.
                </p>
            @else
                <ol class="steps">
                    @foreach ($journey as $i => $step)
                        <li class="{{ $i < $journeyIndex ? 'is-done' : ($i === $journeyIndex ? 'is-current' : '') }}">
                            <span>
                                {{ $step['title'] }}
                                @if ($i === $journeyIndex)
                                    <span class="small muted" style="display:block;font-weight:400">{{ $currentStatus->label() }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    <div class="panel" style="margin-top:20px">
        <p class="eyebrow">Durum geçmişi</p>
        @if ($history === [])
            <p class="body-muted" style="margin:0">Henüz durum değişikliği yok.</p>
        @else
            <table class="data">
                <thead>
                    <tr><th>Tarih</th><th>Geçiş</th><th>Gerekçe</th></tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($history) as $t)
                        <tr>
                            <td>{{ $t->created_at?->format('d.m.Y H:i') }}</td>
                            <td><span class="mono small">{{ $t->from_status?->label() ?? '—' }} → {{ $t->to_status->label() }}</span></td>
                            <td>{{ $t->reason ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
