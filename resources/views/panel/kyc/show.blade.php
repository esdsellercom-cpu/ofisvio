@extends('layouts.panel')

@section('title', 'KYC — '.$company->legal_name)

@php
    use App\Enums\KycDocumentStatus;
    $statusTone = fn (KycDocumentStatus $s) => match ($s) {
        KycDocumentStatus::APPROVED => 'ok',
        KycDocumentStatus::REJECTED, KycDocumentStatus::QUARANTINED => 'danger',
        KycDocumentStatus::MORE_INFO_REQUIRED => 'warn',
        KycDocumentStatus::PENDING, KycDocumentStatus::UNDER_REVIEW => 'info',
        KycDocumentStatus::SUPERSEDED => 'muted',
    };
@endphp

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a></p>
            <h1 class="h2">KYC belgeleri</h1>
        </div>
        <div class="panel-head__actions">
            @include('panel.partials.company-status', ['status' => $company->status])
            @if ($summary['complete'])
                <span class="badge badge--ok">Zorunlu belgeler onaylı</span>
            @else
                <span class="badge badge--warn">{{ count($summary['missing']) }} zorunlu belge eksik</span>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Belge tipleri — müşteri buradan yükler, personel durumu görür     --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Belge</th>
                    <th>Durum</th>
                    <th>Son işlem</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($types as $type)
                    @php($doc = $latestByType[$type->value] ?? null)
                    <tr>
                        <td>
                            {{ $type->label() }}
                            @if ($type->isRequired())
                                <span class="small muted" style="display:block;font-weight:400">Zorunlu</span>
                            @else
                                <span class="small muted" style="display:block;font-weight:400">Yalnızca vekil başvurusunda</span>
                            @endif
                        </td>
                        <td>
                            @if ($doc)
                                <span class="badge badge--{{ $statusTone($doc->status) }}">{{ $doc->status->label() }}</span>
                                @if ($doc->review_note && in_array($doc->status, [KycDocumentStatus::REJECTED, KycDocumentStatus::MORE_INFO_REQUIRED, KycDocumentStatus::QUARANTINED], true))
                                    <div class="small" style="margin-top:6px;color:var(--danger);max-width:36ch">{{ $doc->review_note }}</div>
                                @endif
                            @else
                                <span class="badge badge--muted">Yüklenmedi</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($doc)
                                {{ $doc->created_at?->format('d.m.Y H:i') }}
                                <span class="muted" style="display:block">{{ $doc->original_filename }} · {{ number_format($doc->size_bytes / 1024) }} KB</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <div class="row-actions">
                                @if ($doc && ! $doc->status->contentLocked())
                                    @if ($can['open_as_owner'] || ($grants[$doc->id] ?? false))
                                        <a href="{{ route('panel.companies.kyc.download', [$company, $doc]) }}" class="btn btn--ghost btn--pill">İndir</a>
                                    @elseif ($can['request_jit'])
                                        <a href="#jit-{{ $doc->id }}" class="btn btn--ghost btn--pill">JIT iste</a>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Yükleme (müşteri)                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($can['upload'])
        <div class="panel" style="margin-top:20px;max-width:640px">
            <p class="eyebrow">Belge yükle</p>
            <p class="body-muted" style="margin:0 0 16px">
                PDF, JPG veya PNG; en fazla 10 MB. Aynı tipte yeni belge yüklerseniz eskisi arşivlenir, silinmez.
            </p>
            <form method="POST" action="{{ route('panel.companies.kyc.upload', $company) }}" enctype="multipart/form-data" class="inline-form">
                @csrf
                <label class="field">
                    <span class="label">Belge tipi</span>
                    <select class="control" name="type" required>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span class="label">Dosya</span>
                    <input class="control" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required @error('file') aria-invalid="true" @enderror>
                </label>
                <button type="submit" class="btn btn--brand">Yükle</button>
            </form>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Belge içeriği erişimi — JIT (personel)                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if (! $can['open_as_owner'] && $can['request_jit'] && $latestByType !== [])
        <div class="panel" style="margin-top:20px">
            <p class="eyebrow">Belge içeriği erişimi (JIT)</p>
            <p class="body-muted" style="margin:0 0 16px">
                Durum bilgisini görürsünüz; içerik yalnızca gerekçeli ve süreli erişimle açılır. Her talep
                denetim kaydına yazılır (kim, hangi belge, neden, ne kadar süre).
            </p>
            <div class="grid-auto" style="--min:280px;--gap:14px">
                @foreach ($latestByType as $doc)
                    @continue($doc->status->contentLocked()) {{-- karantina: JIT ile bile açılmaz --}}
                    <div id="jit-{{ $doc->id }}" style="border:1px solid var(--line);border-radius:var(--r-md);padding:16px 18px">
                        <strong>{{ $doc->type->label() }}</strong>
                        <span class="small muted" style="display:block;margin-bottom:12px">{{ $doc->original_filename }}</span>
                        @if ($grants[$doc->id] ?? false)
                            <span class="badge badge--ok">Erişim açık</span>
                            <a href="{{ route('panel.companies.kyc.download', [$company, $doc]) }}" class="btn btn--ghost btn--pill" style="margin-left:8px">İndir</a>
                        @else
                            <form method="POST" action="{{ route('panel.companies.kyc.jit', [$company, $doc]) }}" class="stack" style="gap:10px">
                                @csrf
                                <label class="field">
                                    <span class="label">Gerekçe</span>
                                    <textarea class="control" name="reason" required minlength="10" maxlength="500" style="min-height:72px" placeholder="Örn. MASAK şüpheli işlem incelemesi ref#…"></textarea>
                                </label>
                                <div class="inline-form">
                                    <label class="field" style="flex:0 1 140px">
                                        <span class="label">Süre (dk)</span>
                                        <input class="control" type="number" name="ttl_minutes" value="{{ $defaultTtl }}" min="5" max="{{ $maxTtl }}" required>
                                    </label>
                                    <button type="submit" class="btn btn--brand">Erişim aç</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- İnceleme (personel)                                               --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($can['review'])
        @php($reviewable = $documents->filter(fn ($d) => in_array($d->status, [KycDocumentStatus::PENDING, KycDocumentStatus::UNDER_REVIEW], true)))
        <div class="panel" style="margin-top:20px">
            <p class="eyebrow">İnceleme</p>
            @if ($reviewable->isEmpty())
                <p class="body-muted" style="margin:0">İnceleme bekleyen belge yok.</p>
            @else
                <div class="stack" style="gap:18px">
                    @foreach ($reviewable as $doc)
                        <div style="border:1px solid var(--line);border-radius:var(--r-md);padding:16px 18px">
                            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
                                <div>
                                    <strong>{{ $doc->type->label() }}</strong>
                                    <span class="small muted" style="display:block">{{ $doc->original_filename }} · yüklendi {{ $doc->created_at?->format('d.m.Y H:i') }}</span>
                                </div>
                                <span class="badge badge--{{ $statusTone($doc->status) }}">{{ $doc->status->label() }}</span>
                            </div>

                            <form method="POST" class="stack" style="gap:10px;margin-top:14px" data-review-form>
                                @csrf
                                <label class="field">
                                    <span class="label">Not (red ve ek bilgi talebinde zorunlu)</span>
                                    <textarea class="control" name="note" maxlength="2000" placeholder="Müşterinin göreceği açıklama">{{ old('note') }}</textarea>
                                </label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <button type="submit" class="btn btn--brand" formaction="{{ route('panel.companies.kyc.approve', [$company, $doc]) }}">Onayla</button>
                                    <button type="submit" class="btn btn--ghost" formaction="{{ route('panel.companies.kyc.more-info', [$company, $doc]) }}">Ek bilgi iste</button>
                                    <button type="submit" class="btn btn--ghost" style="color:var(--danger);border-color:#E9C4BC" formaction="{{ route('panel.companies.kyc.reject', [$company, $doc]) }}">Reddet</button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Tüm geçmiş (arşivlenenler dahil)                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($documents->count() > count($latestByType))
        <details style="margin-top:20px">
            <summary class="small muted" style="cursor:pointer">Arşivlenen belgeler ({{ $documents->count() - count($latestByType) }})</summary>
            <div class="table-wrap" style="margin-top:10px">
                <table class="data">
                    <thead><tr><th>Belge</th><th>Durum</th><th>Yüklendi</th><th>Not</th></tr></thead>
                    <tbody>
                        @foreach ($documents as $doc)
                            @continue(($latestByType[$doc->type->value] ?? null)?->id === $doc->id)
                            <tr>
                                <td>{{ $doc->type->label() }} <span class="small muted" style="display:block;font-weight:400">{{ $doc->original_filename }}</span></td>
                                <td><span class="badge badge--{{ $statusTone($doc->status) }}">{{ $doc->status->label() }}</span></td>
                                <td class="small">{{ $doc->created_at?->format('d.m.Y H:i') }}</td>
                                <td class="small">{{ $doc->review_note ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
@endsection
