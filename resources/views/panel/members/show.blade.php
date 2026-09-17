@extends('layouts.panel')

@section('title', $member->user->name.' — üye profili')

{{-- 360° üye profili (faz 51): özet kartları, uyarılar, hızlı işlemler; sekmeler Özet · Finans · Sözleşmeler · Tahsisler ·
     Hizmetler · Belgeler · Aktivite. Yazma işlemleri mevcut rotalara (tahsilat, fatura, üyelik, tahsis, belge) `return` ile;
     profil/sözleşme/ek harcama üye merkezi rotalarına. Finans şirket bazlıdır. --}}
@php($p = $member->profile)
@php($openModal = old('_modal'))
@php($activeContract = $contracts->first(fn ($c) => $c->status === 'active'))
@php($activeSubs = $subscriptions->where('status', 'active'))
@php($methods = $paymentMethods)
@php($currency = $finance['currency'])
@php($tabs = ['ozet' => 'Özet', 'finans' => 'Finans', 'sozlesme' => 'Sözleşmeler', 'tahsis' => 'Tahsisler', 'hizmet' => 'Hizmetler', 'belge' => 'Belgeler', 'aktivite' => 'Aktivite'])
@php($tabUrl = fn (string $t) => route('panel.members.show', $member).'?sekme='.$t)
@section('content')
    <div class="panel-head">
        <div style="display:flex;gap:14px;align-items:center">
            @if ($p?->avatar)<img src="{{ $p->avatar->urlFor(240) }}" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover">@else<span class="ap-av" style="width:64px;height:64px;font-size:24px" aria-hidden="true">{{ mb_strtoupper(mb_substr($member->user->name, 0, 1)) }}</span>@endif
            <div>
                <p class="eyebrow"><a href="{{ route('panel.members.index') }}">Üyeler</a> · <a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a>@if ($p?->member_no) · <span class="mono">{{ $p->member_no }}</span>@endif</p>
                <h1 class="h2" style="margin:0">{{ $member->user->name }}</h1>
                <p class="small muted" style="margin:4px 0 0">{{ $p?->title ? $p->title.' · ' : '' }}{{ $member->user->email }}{{ $p?->phone ? ' · '.$p->phone : '' }} · {{ __('roles.'.$member->role->name) }}
                    @if ($member->isActive())<span class="pill g flat">Aktif üye</span>@else<span class="pill n flat">Pasif</span>@endif
                    @if ($p?->membership_type)<span class="pill n flat">{{ \App\Models\MemberProfile::MEMBERSHIP_TYPES[$p->membership_type] ?? $p->membership_type }}</span>@endif
                </p>
            </div>
        </div>
        <div class="panel-head__actions" style="flex-wrap:wrap">
            @if ($can['payment'] && $openInvoices->isNotEmpty())<button type="button" class="btn btn--brand" data-modal-open="#modal-payment" data-title="Tahsilat · {{ $company->legal_name }}" data-fill="{{ json_encode(['company_id' => $company->id, 'paid_on' => now()->toDateString()]) }}">Tahsilat ekle</button>@endif
            @if ($can['invoice'])<button type="button" class="btn btn--ghost" data-modal-open="#modal-charge">Ek harcama</button><button type="button" class="btn btn--ghost" data-modal-open="#modal-invoice">Fatura oluştur</button>@endif
            @if ($can['contract'])<button type="button" class="btn btn--ghost" data-modal-open="#modal-contract">Sözleşme ekle</button>@endif
            @if ($can['subscription'])<button type="button" class="btn btn--ghost" data-modal-open="#modal-subscription">Hizmet ekle</button>@endif
            @if ($can['space'])<button type="button" class="btn btn--ghost" data-modal-open="#modal-assign">Tahsis et</button>@if ($activeAssignments->isNotEmpty())<button type="button" class="btn btn--ghost" data-modal-open="#modal-asset">Demirbaş ekle</button>@endif @endif
            @if ($can['payment'] || $can['contract'])<button type="button" class="btn btn--ghost" data-modal-open="#modal-document">Belge oluştur</button>@endif
            @if ($can['manage'])<a href="{{ route('panel.members.edit', $member) }}" class="btn btn--ghost">Düzenle</a>@endif
        </div>
    </div>

    @if ($alerts !== [])
        <div class="stack" style="gap:6px;margin-bottom:14px">
            @foreach ($alerts as $a)<div class="notice {{ $a['level'] === 'c' ? 'notice--error' : '' }}" role="status" style="padding:8px 12px"><span class="notice__dot" aria-hidden="true"></span><div>⚠ {{ $a['text'] }}</div></div>@endforeach
        </div>
    @endif

    {{-- Özet kartları --}}
    <div class="kpis" style="margin-bottom:16px">
        <div class="kpi {{ $finance['remaining'] > 0 ? 'warn' : '' }}"><span class="k">Borç</span><span class="v">{{ money($finance['remaining'], $currency) }}</span><span class="d">Gecikmiş {{ money($finance['overdue'], $currency) }}</span></div>
        <div class="kpi"><span class="k">Ödenen</span><span class="v">{{ money($finance['paid'], $currency) }}</span><span class="d">Toplam borç {{ money($finance['invoiced'], $currency) }}</span></div>
        <div class="kpi {{ $finance['balance'] > 0 ? 'ok' : '' }}"><span class="k">Bakiye</span><span class="v">{{ money($finance['balance'], $currency) }}</span><span class="d">ödenen − borç (alacak)</span></div>
        <div class="kpi"><span class="k">Aktif hizmet</span><span class="v">{{ $activeSubs->count() }}</span><span class="d">{{ $activeSubs->first()?->plan?->name ?? '—' }}</span></div>
        <div class="kpi"><span class="k">Tahsis</span><span class="v" style="font-size:20px">{{ $activeAssignments->first()?->space?->name ?? '—' }}</span><span class="d">{{ $activeAssignments->count() }} aktif · {{ $activeAssignments->sum(fn ($a) => $a->assets->count()) }} demirbaş</span></div>
        <div class="kpi {{ $activeContract?->daysLeft() !== null && $activeContract->daysLeft() <= 15 ? 'warn' : '' }}"><span class="k">Sözleşme</span><span class="v" style="font-size:20px">@if ($activeContract){{ $activeContract->daysLeft() === null ? 'Süresiz' : ($activeContract->daysLeft() < 0 ? 'Doldu' : $activeContract->daysLeft().' gün kaldı') }}@else — @endif</span><span class="d">{{ $activeContract?->number ?? 'sözleşme yok' }}</span></div>
        <div class="kpi"><span class="k">Son ödeme</span><span class="v" style="font-size:20px">{{ $finance['last_payment']?->format('d.m.Y') ?? '—' }}</span><span class="d">{{ $finance['last_payment'] ? money($finance['last_payment_amount'], $currency) : 'tahsilat yok' }}</span></div>
    </div>

    <nav class="tabbar" style="margin-bottom:14px">
        @foreach ($tabs as $k => $l)<a href="{{ $tabUrl($k) }}" @if ($tab === $k) aria-current="page" @endif>{{ $l }}@if ($k === 'sozlesme') ({{ $contracts->count() }})@elseif ($k === 'belge') ({{ $documents->count() }})@endif</a>@endforeach
    </nav>

    @error('builder')<div class="notice notice--error" role="alert" style="margin-bottom:14px"><span class="notice__dot" aria-hidden="true"></span><div>{{ $message }}</div></div>@enderror

    {{-- ================= ÖZET ================= --}}
    @if ($tab === 'ozet')
        <div class="grid g-2-1" style="align-items:start">
            <div class="stack" style="gap:14px">
                <div class="card"><div class="card__head"><h3>Önemli tarihler</h3></div><div class="card__body"><dl class="dl">@foreach ($dates as $d)<dt>{{ $d['label'] }}</dt><dd>{{ $d['date']?->format('d.m.Y') ?? '—' }} @if ($d['warn'])<span class="pill w flat">⚠ yaklaşıyor / geçti</span>@endif</dd>@endforeach</dl></div></div>
                <div class="card"><div class="card__head"><h3>Son finansal hareketler</h3><a href="{{ $tabUrl('finans') }}" class="btn btn--quiet">Tümü</a></div>
                    @include('panel.members.partials.ledger', ['rows' => array_slice($ledger, 0, 6)])
                </div>
                <div class="card"><div class="card__head"><h3>Son aktivite</h3><a href="{{ $tabUrl('aktivite') }}" class="btn btn--quiet">Tümü</a></div>
                    @include('panel.members.partials.activity', ['rows' => $activity->take(6)])
                </div>
            </div>
            <div class="stack" style="gap:14px">
                <div class="card"><div class="card__head"><h3>Kişi</h3></div><div class="card__body"><dl class="dl">
                    <dt>Ad Soyad</dt><dd>{{ $member->user->name }}</dd><dt>E-posta</dt><dd>{{ $member->user->email }}</dd><dt>Telefon</dt><dd>{{ $p?->phone ?? '—' }}</dd><dt>Ünvan</dt><dd>{{ $p?->title ?? '—' }}</dd>
                    <dt>TC / Vergi no</dt><dd class="mono">{{ $p?->maskedIdentity() ?: '—' }}</dd><dt>Adres</dt><dd>{{ trim(($p?->address ?? '').' '.($p?->city ?? '').' '.($p?->country ?? '')) ?: '—' }}</dd>
                    <dt>Firma</dt><dd><a href="{{ route('panel.companies.show', $company) }}">{{ $company->legal_name }}</a>@if ($company->tax_number) · VKN {{ $company->tax_number }}@endif</dd>
                    <dt>Üye no</dt><dd class="mono">{{ $p?->member_no ?? '—' }}</dd><dt>Üyelik tipi</dt><dd>{{ $p?->membership_type ? (\App\Models\MemberProfile::MEMBERSHIP_TYPES[$p->membership_type] ?? '') : '—' }}</dd>
                    <dt>Durum</dt><dd>{{ $member->isActive() ? 'Aktif' : 'Pasif (askıda)' }}</dd>
                </dl>@if ($p?->note)<p class="small" style="margin:10px 0 0;white-space:pre-wrap">{{ $p->note }}</p>@endif</div></div>
                <div class="card"><div class="card__head"><h3>Aktif tahsisler</h3></div>
                    @forelse ($activeAssignments as $a)<div class="row"><div class="main-t"><b>{{ $a->space?->name }}</b><span>{{ $a->starts_on->format('d.m.Y') }} → {{ $a->ends_on?->format('d.m.Y') ?? 'süresiz' }}@if ($a->assets->isNotEmpty()) · {{ $a->assets->pluck('name')->implode(', ') }}@endif</span></div></div>@empty<div class="empty-state" style="border:0">Aktif tahsis yok.</div>@endforelse
                </div>
                <div class="card"><div class="card__head"><h3>Hizmetler</h3></div>
                    @forelse ($activeSubs as $s)<div class="row"><div class="main-t"><b>{{ $s->plan?->name }}</b><span>{{ $s->starts_on?->format('d.m.Y') }} – {{ $s->ends_on?->format('d.m.Y') ?? '—' }} · {{ money($s->price, $currency) }}</span></div></div>@empty<div class="empty-state" style="border:0">Aktif hizmet yok.</div>@endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ================= FİNANS ================= --}}
    @if ($tab === 'finans')
        <div class="kpis" style="margin-bottom:14px">
            <div class="kpi"><span class="k">Toplam borç</span><span class="v">{{ money($finance['invoiced'], $currency) }}</span><span class="d">fatura + faturasız ek harcama</span></div>
            <div class="kpi ok"><span class="k">Ödenen</span><span class="v">{{ money($finance['paid'], $currency) }}</span><span class="d">toplam tahsilat {{ money($finance['payments_total'], $currency) }}</span></div>
            <div class="kpi {{ $finance['remaining'] > 0 ? 'warn' : '' }}"><span class="k">Kalan borç</span><span class="v">{{ money($finance['remaining'], $currency) }}</span><span class="d">bekleyen {{ money($finance['pending'], $currency) }}</span></div>
            <div class="kpi {{ $finance['overdue'] > 0 ? 'warn' : '' }}"><span class="k">Geciken</span><span class="v">{{ money($finance['overdue'], $currency) }}</span><span class="d">vadesi geçmiş</span></div>
            <div class="kpi"><span class="k">Bakiye</span><span class="v">{{ money($finance['balance'], $currency) }}</span><span class="d">alacak</span></div>
            <div class="kpi"><span class="k">Son tahsilat</span><span class="v" style="font-size:20px">{{ $finance['last_payment']?->format('d.m.Y') ?? '—' }}</span><span class="d">{{ $finance['last_payment'] ? money($finance['last_payment_amount'], $currency) : '—' }}</span></div>
        </div>
        <div class="card" style="margin-bottom:14px"><div class="card__head"><h3>Açık faturalar</h3><span class="sub">{{ $openInvoices->count() }} · <a href="{{ route('panel.collections.index') }}">Tahsilat merkezi</a></span></div>
            @if ($openInvoices->isEmpty())<div class="empty-state" style="border:0">Açık fatura yok.</div>@else
            <div class="tw"><table class="t"><thead><tr><th>Fatura</th><th>Açıklama</th><th>Vade</th><th class="num">Tutar</th><th class="num">Kalan</th><th></th></tr></thead><tbody>
                @foreach ($openInvoices as $inv)<tr><td><a href="{{ route('panel.invoices.show', $inv) }}" class="mono">{{ $inv->number }}</a></td><td>{{ $inv->description }}</td><td>{{ $inv->due_on?->format('d.m.Y') }} @if ($inv->daysOverdue() > 0)<span class="pill c flat">{{ $inv->daysOverdue() }} gün gecikti</span>@endif</td><td class="num">{{ money($inv->total, $inv->currency) }}</td><td class="num"><b>{{ money($inv->outstanding(), $inv->currency) }}</b></td><td class="num">@if ($can['payment'])<button type="button" class="btn btn--quiet" data-modal-open="#modal-payment" data-title="Tahsilat · {{ $inv->number }}" data-fill="{{ json_encode(['company_id' => $inv->company_id, 'invoice_id' => $inv->id, 'amount' => \App\Support\Money::major($inv->outstanding()), 'description' => $inv->description, 'paid_on' => now()->toDateString()]) }}">Tahsilat ekle</button>@if ($inv->daysOverdue() > 0)<form method="POST" action="{{ route('panel.collections.invoices.notice', $inv->id) }}" style="display:inline">@csrf<input type="hidden" name="return" value="{{ $returnUrl }}?sekme=belge"><button type="submit" class="btn btn--quiet">Gecikme belgesi</button></form>@endif @endif</td></tr>@endforeach
            </tbody></table></div>@endif
        </div>
        <div class="card"><div class="card__head"><h3>Hareket geçmişi</h3><span class="sub">fatura · tahsilat · ek harcama · iptal</span></div>@include('panel.members.partials.ledger', ['rows' => $ledger])</div>
    @endif

    {{-- ================= SÖZLEŞMELER ================= --}}
    @if ($tab === 'sozlesme')
        <div class="card">
            <div class="card__head"><h3>Sözleşmeler</h3>@if ($can['contract'])<button type="button" class="btn btn--brand" data-modal-open="#modal-contract">Yeni sözleşme</button>@endif</div>
            @if ($contracts->isEmpty())<div class="empty-state" style="border:0">Sözleşme yok.</div>@else
            <div class="tw"><table class="t"><thead><tr><th>Sözleşme no</th><th>Tür</th><th>Başlangıç</th><th>Bitiş</th><th>Durum</th><th>Üye</th><th>Oluşturma / güncelleme</th><th>Dosya</th><th>Not</th><th></th></tr></thead><tbody>
                @foreach ($contracts as $c)
                    @php($dl = $c->daysLeft())
                    <tr>
                        <td class="mono">{{ $c->number }}</td><td>{{ $c->typeLabel() }}</td><td>{{ $c->starts_on->format('d.m.Y') }}</td><td>{{ $c->ends_on?->format('d.m.Y') ?? 'Süresiz' }}@if ($dl !== null && $dl >= 0 && $dl <= 15) <span class="pill w flat">{{ $dl }} gün</span>@endif</td>
                        <td><span class="pill {{ ['active' => 'g', 'draft' => 'n', 'ended' => 'n', 'expired' => 'c'][$c->effectiveStatus()] ?? 'n' }} flat">{{ $c->statusLabel() }}</span></td>
                        <td class="small">{{ $c->membership?->user?->name ?? 'Firma' }}</td>
                        <td class="small">{{ $c->created_at?->format('d.m.Y') }} · {{ $c->updated_at?->format('d.m.Y') }}<br><span class="muted">{{ $c->creator?->name }}</span></td>
                        <td>@if ($c->file_path)<a href="{{ route('panel.members.contract.file', [$member->id, $c->id]) }}">İndir</a>@else<span class="muted">—</span>@endif @foreach ($c->documents as $d)<a href="{{ route('panel.collections.documents.pdf', $d) }}" class="mono small" title="Belge PDF">{{ $d->number }}</a> @endforeach</td>
                        <td class="small" style="max-width:220px">{{ \Illuminate\Support\Str::limit($c->note ?? '', 80) }}</td>
                        <td class="num" style="white-space:nowrap">
                            @if ($can['contract'])
                                @if ($c->status !== 'ended')<button type="button" class="btn btn--quiet" data-modal-open="#modal-contract" data-title="Sözleşme düzenle · {{ $c->number }}" data-method="PUT" data-action="{{ route('panel.members.contract.update', [$company->id, $member->id, $c->id]) }}" data-fill="{{ json_encode(['type' => $c->type, 'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on?->format('Y-m-d'), 'status' => $c->status, 'note' => $c->note]) }}">Düzenle</button>@endif
                                <form method="POST" action="{{ route('panel.members.contract.document', [$company->id, $member->id, $c->id]) }}" style="display:inline">@csrf<button type="submit" class="btn btn--quiet" title="Belge motoruyla PDF sözleşme belgesi">PDF</button></form>
                                @if ($c->status !== 'ended')<button type="button" class="btn btn--quiet" style="color:var(--danger)" data-modal-open="#modal-contract-end" data-action="{{ route('panel.members.contract.end', [$company->id, $member->id, $c->id]) }}" data-title="Sonlandır · {{ $c->number }}">Sonlandır</button>@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>@endif
        </div>
    @endif

    {{-- ================= TAHSİSLER ================= --}}
    @if ($tab === 'tahsis')
        <div class="card">
            <div class="card__head"><h3>Masa / ofis / oda tahsisleri &amp; demirbaşlar</h3><span class="sub"><a href="{{ route('panel.spaces.index') }}">Envanter</a></span>@if ($can['space'])<button type="button" class="btn btn--brand" data-modal-open="#modal-assign">Tahsis et</button>@endif</div>
            @if ($assignments->isEmpty())<div class="empty-state" style="border:0">Tahsis yok.</div>@else
            <div class="rows">
                @foreach ($assignments as $a)
                    <div class="row" style="align-items:flex-start">
                        <div class="main-t"><b>{{ $a->space?->name }} <span class="muted small">{{ $a->space?->location?->name }} · {{ $a->space?->inventoryLabel() }}</span></b>
                            <span>{{ $a->starts_on->format('d.m.Y') }} → {{ $a->ends_on?->format('d.m.Y') ?? 'süresiz' }} · <span class="pill {{ $a->status === 'active' ? 'g' : 'n' }} flat">{{ $a->status === 'active' ? 'Aktif' : 'Bitti' }}</span>@if ($a->user) · {{ $a->user->name }}@endif @if ($a->subscription) · {{ $a->subscription->plan?->name }}@endif</span>
                            <span class="small">Demirbaşlar: @if ($a->assets->isEmpty())<span class="muted">yok</span>@else @foreach ($a->assets as $x)<span class="tag">{{ $x->name }}{{ $x->code ? ' ('.$x->code.')' : '' }}</span> @endforeach @endif</span>
                        </div>
                        <span class="rt">@if ($can['space'] && $a->status === 'active')<button type="button" class="btn btn--quiet" data-modal-open="#modal-asset" data-fill="{{ json_encode(['assignment_id' => $a->id]) }}">Demirbaş</button><form method="POST" action="{{ route('panel.spaces.inventory.assignment.end', $a->id) }}" style="display:inline" onsubmit="return confirm('Tahsis bitirilsin mi? Demirbaşlar iade alınır.')">@csrf<input type="hidden" name="return" value="{{ $returnUrl }}?sekme=tahsis"><button type="submit" class="btn btn--quiet">Bitir</button></form>@endif</span>
                    </div>
                @endforeach
            </div>@endif
        </div>
    @endif

    {{-- ================= HİZMETLER ================= --}}
    @if ($tab === 'hizmet')
        <div class="card">
            <div class="card__head"><h3>Üyelik / hizmetler</h3><span class="sub"><a href="{{ route('panel.subscriptions.index') }}">Üyelikler</a></span>@if ($can['subscription'])<button type="button" class="btn btn--brand" data-modal-open="#modal-subscription">Hizmet ekle</button>@endif</div>
            @if ($subscriptions->isEmpty())<div class="empty-state" style="border:0">Hizmet yok.</div>@else
            <div class="tw"><table class="t"><thead><tr><th>Hizmet</th><th>Başlangıç</th><th>Bitiş</th><th class="num">Ücret</th><th>Durum</th><th>Yenileme</th><th></th></tr></thead><tbody>
                @foreach ($subscriptions as $s)<tr><td><b>{{ $s->plan?->name }}</b><small style="display:block">{{ $s->location?->name }}</small></td><td>{{ $s->starts_on?->format('d.m.Y') }}</td><td>{{ $s->ends_on?->format('d.m.Y') ?? '—' }}</td><td class="num">{{ money($s->price, $currency) }} / {{ $s->period }}</td><td><span class="pill {{ $s->status === 'active' ? 'g' : 'n' }} flat">{{ $s->status }}</span></td><td class="small">{{ $s->auto_renew ? 'otomatik · '.($s->ends_on?->format('d.m.Y') ?? '') : 'manuel' }}</td><td class="num"><a href="{{ route('panel.subscriptions.show', $s) }}" class="btn btn--quiet">Aç</a></td></tr>@endforeach
            </tbody></table></div>@endif
        </div>
    @endif

    {{-- ================= BELGELER ================= --}}
    @if ($tab === 'belge')
        <div class="card">
            <div class="card__head"><h3>Belgeler</h3><span class="sub">sözleşme · fatura · makbuz · gecikme belgesi</span>@if ($can['payment'] || $can['contract'])<button type="button" class="btn btn--brand" data-modal-open="#modal-document">Belge oluştur</button>@endif</div>
            <div class="tw"><table class="t"><thead><tr><th>Belge</th><th>Tür</th><th>Tarih</th><th>Durum</th><th>Oluşturan</th><th></th></tr></thead><tbody>
                @foreach ($contracts as $c)<tr><td class="mono">{{ $c->number }}</td><td>Sözleşme · {{ $c->typeLabel() }}</td><td>{{ $c->starts_on->format('d.m.Y') }}</td><td>{{ $c->statusLabel() }}</td><td class="small">{{ $c->creator?->name }}</td><td class="num">@if ($c->file_path)<a href="{{ route('panel.members.contract.file', [$member->id, $c->id]) }}" class="btn btn--quiet">Dosya</a>@endif</td></tr>@endforeach
                @foreach ($invoices as $inv)<tr><td class="mono">{{ $inv->number ?? 'Taslak #'.$inv->id }}</td><td>Fatura</td><td>{{ $inv->issued_on?->format('d.m.Y') ?? $inv->created_at?->format('d.m.Y') }}</td><td>{{ $inv->statusLabel() }}</td><td class="small">{{ $inv->creator?->name ?? 'Sistem' }}</td><td class="num"><a href="{{ route('panel.invoices.show', $inv) }}" class="btn btn--quiet">Aç</a></td></tr>@endforeach
                @foreach ($documents as $d)<tr><td class="mono">{{ $d->number }}</td><td>{{ \App\Models\Document::KINDS[$d->kind] ?? $d->kind }}</td><td>{{ $d->created_at?->format('d.m.Y') }}</td><td>{{ $d->isCancelled() ? 'İptal' : 'Geçerli' }}</td><td class="small">{{ $d->creator?->name }}</td><td class="num"><a href="{{ route('panel.collections.documents.show', $d) }}" class="btn btn--quiet">Aç</a> <a href="{{ route('panel.collections.documents.pdf', $d) }}" class="btn btn--quiet">PDF</a></td></tr>@endforeach
                @if ($contracts->isEmpty() && $invoices->isEmpty() && $documents->isEmpty())<tr><td colspan="6" class="muted">Belge yok.</td></tr>@endif
            </tbody></table></div>
        </div>
    @endif

    {{-- ================= AKTİVİTE ================= --}}
    @if ($tab === 'aktivite')
        <div class="card"><div class="card__head"><h3>Aktivite geçmişi</h3><span class="sub">kim · ne · ne zaman (denetim izi)</span></div>@include('panel.members.partials.activity', ['rows' => $activity])</div>
    @endif

    {{-- ================= MODALLAR ================= --}}
    @if ($can['payment'] && $openInvoices->isNotEmpty())
        @include('panel.collections.partials.modal-payment', ['openInvoices' => $openInvoices->each(fn ($i) => $i->setRelation('company', $company)), 'methods' => $methods, 'currency' => $currency, 'openModal' => $openModal, 'returnUrl' => $returnUrl.'?sekme=finans'])
    @endif

    @if ($can['invoice'])
    <dialog class="modal" id="modal-charge" @if ($openModal === 'charge') data-modal-auto @endif>
        <form method="POST" action="{{ route('panel.members.charge.store', [$company->id, $member->id]) }}" data-modal-form>@csrf<input type="hidden" name="_modal" value="charge">
            <div class="modal__head"><h2>Ek harcama</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                @if ($openModal === 'charge' && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
                <div class="grid-auto" style="--min:180px;--gap:10px">
                    <label class="field"><span class="label">Harcama türü</span><select class="control" name="kind">@foreach ($chargeKinds as $k => $l)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $l }}</option>@endforeach</select></label>
                    <label class="field"><span class="label">Tutar</span><input class="control mono" type="text" name="amount" value="{{ old('amount') }}" required inputmode="decimal" placeholder="0,00"></label>
                    <label class="field"><span class="label">Para birimi</span><input class="control mono" type="text" value="{{ $currency }}" readonly></label>
                    <label class="field"><span class="label">Tarih</span><input class="control mono" type="date" name="charged_on" value="{{ old('charged_on', now()->toDateString()) }}" required></label>
                </div>
                <label class="field"><span class="label">Açıklama</span><input class="control" type="text" name="description" value="{{ old('description') }}" required maxlength="200"></label>
                <label class="field"><span class="label">Faturalama</span>
                    <select class="control" name="billing">
                        <option value="invoice" @selected(old('billing') === 'invoice')>Yeni fatura kes ve yayınla (önerilen)</option>
                        <option value="none" @selected(old('billing') === 'none')>Faturasız kaydet — bakiyeye doğrudan yansır</option>
                        @foreach ($invoices->filter(fn ($i) => $i->status === 'draft') as $inv)<option value="link:{{ $inv->id }}" @selected(old('billing') === 'link:'.$inv->id)>Taslak faturaya bağla: {{ $inv->description }} (#{{ $inv->id }})</option>@endforeach
                    </select>
                    <span class="small muted">Mevcut faturaya bağlama bilgi amaçlıdır; tutar o faturada olmalı.</span>
                </label>
                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" value="{{ old('note') }}" maxlength="500"></label>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-invoice">
        <form method="POST" action="{{ route('panel.invoices.store') }}" data-modal-form>@csrf<input type="hidden" name="company_id" value="{{ $company->id }}"><input type="hidden" name="return" value="{{ $returnUrl }}?sekme=finans">
            <div class="modal__head"><h2>Fatura oluştur</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Açıklama / hizmet</span><input class="control" type="text" name="description" required maxlength="300"></label>
                <div class="grid-auto" style="--min:160px;--gap:10px">
                    <label class="field"><span class="label">Ara toplam (KDV hariç)</span><input class="control mono" type="text" name="subtotal" required inputmode="decimal" placeholder="0,00"></label>
                    <label class="field"><span class="label">KDV %</span><input class="control mono" type="number" name="tax_rate" min="0" max="100" placeholder="ayar"></label>
                    <label class="field"><span class="label">Vade</span><input class="control mono" type="date" name="due_on"></label>
                    <label class="field"><span class="label">Üyelik</span><select class="control" name="subscription_id"><option value="">—</option>@foreach ($subscriptions as $s)<option value="{{ $s->id }}">{{ $s->plan?->name }} ({{ $s->starts_on?->format('d.m.Y') }})</option>@endforeach</select></label>
                </div>
                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" maxlength="1000"></label>
                <label class="checkbox-row"><input type="checkbox" name="issue" value="1" checked><span>Hemen yayınla (tahsilata açılır)</span></label>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Oluştur</button></div>
        </form>
    </dialog>
    @endif

    @if ($can['contract'])
    <dialog class="modal" id="modal-contract" @if ($openModal === 'contract') data-modal-auto @endif>
        <form method="POST" action="{{ route('panel.members.contract.store', [$company->id, $member->id]) }}" enctype="multipart/form-data" data-modal-form>@csrf<input type="hidden" name="_method" value="POST"><input type="hidden" name="_modal" value="contract">
            <div class="modal__head"><h2 data-modal-title data-default="Yeni sözleşme">Yeni sözleşme</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                @if ($openModal === 'contract' && $errors->any())<div class="notice notice--error" role="alert"><span class="notice__dot" aria-hidden="true"></span><div>{{ $errors->first() }}</div></div>@endif
                <div class="grid-auto" style="--min:170px;--gap:10px">
                    <label class="field"><span class="label">Sözleşme türü</span><select class="control" name="type">@foreach ($contractTypes as $k => $l)<option value="{{ $k }}" @selected(old('type', 'uyelik') === $k)>{{ $l }}</option>@endforeach</select></label>
                    <label class="field"><span class="label">Durum</span><select class="control" name="status"><option value="active" @selected(old('status', 'active') === 'active')>Aktif</option><option value="draft" @selected(old('status') === 'draft')>Taslak</option></select></label>
                    <label class="field"><span class="label">Başlangıç</span><input class="control mono" type="date" name="starts_on" value="{{ old('starts_on', now()->toDateString()) }}" required></label>
                    <label class="field"><span class="label">Bitiş</span><input class="control mono" type="date" name="ends_on" value="{{ old('ends_on') }}"><span class="small muted">Boş = süresiz. Bitişe {{ \App\Models\Contract::WARN_DAYS }} gün kala uyarı.</span></label>
                </div>
                <label class="field"><span class="label">Dosya (PDF/JPG/PNG, 10 MB)</span><input class="control" type="file" name="file" accept="application/pdf,image/jpeg,image/png"></label>
                <label class="field"><span class="label">Not</span><textarea class="control" name="note" rows="3" maxlength="2000">{{ old('note') }}</textarea></label>
                <p class="small muted" style="margin:0">Numara otomatik: SOZ-{{ now()->format('Y') }}-… · PDF belge (şablonlu) sözleşme satırındaki <b>PDF</b> ile üretilir.</p>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-contract-end">
        <form method="POST" data-modal-form>@csrf
            <div class="modal__head"><h2 data-modal-title data-default="Sözleşmeyi sonlandır">Sözleşmeyi sonlandır</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body"><label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="reason" required minlength="3" maxlength="200"></label></div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand" style="background:var(--danger)">Sonlandır</button></div>
        </form>
    </dialog>
    @endif

    @if ($can['subscription'])
    <dialog class="modal" id="modal-subscription">
        <form method="POST" action="{{ route('panel.subscriptions.store') }}" data-modal-form>@csrf<input type="hidden" name="company_id" value="{{ $company->id }}"><input type="hidden" name="return" value="{{ $returnUrl }}?sekme=hizmet">
            <div class="modal__head"><h2>Hizmet (üyelik) ekle</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Plan</span><select class="control" name="plan_id" required>@foreach ($plans as $pl)<option value="{{ $pl->id }}">{{ $pl->name }} — {{ money($pl->price, $currency) }} / {{ $pl->period }}</option>@endforeach</select></label>
                <div class="grid-auto" style="--min:160px;--gap:10px">
                    <label class="field"><span class="label">Başlangıç</span><input class="control mono" type="date" name="starts_on" value="{{ now()->toDateString() }}" required></label>
                    <label class="field"><span class="label">Süre (ay)</span><input class="control mono" type="number" name="months" value="12" min="1" max="36" required></label>
                </div>
                <label class="checkbox-row"><input type="checkbox" name="auto_renew" value="1"><span>Otomatik yenile</span></label>
                <label class="checkbox-row"><input type="checkbox" name="issue_invoice" value="1" checked><span>İlk dönem faturasını kes</span></label>
                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" maxlength="1000"></label>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Hizmeti aç</button></div>
        </form>
    </dialog>
    @endif

    @if ($can['space'])
    <dialog class="modal" id="modal-assign">
        <form method="POST" action="{{ route('panel.spaces.inventory.assign') }}" data-modal-form>@csrf<input type="hidden" name="company_id" value="{{ $company->id }}"><input type="hidden" name="return" value="{{ $returnUrl }}?sekme=tahsis"><input type="hidden" name="_modal" value="assign">
            <div class="modal__head"><h2>Tahsis et</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Alan (masa / ofis)</span><select class="control" name="space_id" required>@foreach ($spaces as $sp)<option value="{{ $sp->id }}">{{ $sp->location?->name }} · {{ $sp->name }} ({{ $sp->inventoryLabel() }})</option>@endforeach</select>@if ($spaces->isEmpty())<span class="small muted">Müsait alan yok.</span>@endif</label>
                <div class="grid-auto" style="--min:160px;--gap:10px">
                    <label class="field"><span class="label">Kişi</span><select class="control" name="user_id"><option value="">Firma geneli</option>@foreach ($members->filter->isActive() as $mm)<option value="{{ $mm->user_id }}" @selected($mm->user_id === $member->user_id)>{{ $mm->user->name }}</option>@endforeach</select></label>
                    <label class="field"><span class="label">Üyelik</span><select class="control" name="subscription_id"><option value="">—</option>@foreach ($activeSubs as $s)<option value="{{ $s->id }}">{{ $s->plan?->name }}</option>@endforeach</select></label>
                    <label class="field"><span class="label">Başlangıç</span><input class="control mono" type="date" name="starts_on" value="{{ now()->toDateString() }}" required></label>
                    <label class="field"><span class="label">Bitiş</span><input class="control mono" type="date" name="ends_on"></label>
                </div>
                @if ($assignableAssets->isNotEmpty())<div class="field"><span class="label">Demirbaş teslim et</span><div style="display:flex;flex-wrap:wrap;gap:6px">@foreach ($assignableAssets as $x)<label class="checkbox-row" style="margin:0"><input type="checkbox" name="asset_ids[]" value="{{ $x->id }}"><span>{{ $x->name }}{{ $x->code ? ' ('.$x->code.')' : '' }}</span></label>@endforeach</div></div>@endif
                <label class="field"><span class="label">Not</span><input class="control" type="text" name="note" maxlength="300"></label>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand" @disabled($spaces->isEmpty())>Tahsis et</button></div>
        </form>
    </dialog>
    @if ($activeAssignments->isNotEmpty())
    <dialog class="modal" id="modal-asset">
        <form method="POST" data-modal-form data-asset-form>@csrf @method('PUT')<input type="hidden" name="return" value="{{ $returnUrl }}?sekme=tahsis">
            <div class="modal__head"><h2>Demirbaş ekle / güncelle</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
            <div class="modal__body stack" style="gap:10px">
                <label class="field"><span class="label">Tahsis</span><select class="control" name="assignment_id" data-asset-assignment>@foreach ($activeAssignments as $a)<option value="{{ $a->id }}" data-action="{{ route('panel.spaces.inventory.assignment.update', $a->id) }}" data-current="{{ $a->assets->pluck('id')->implode(',') }}" data-ends="{{ $a->ends_on?->format('Y-m-d') }}">{{ $a->space?->name }} · {{ $a->starts_on->format('d.m.Y') }}</option>@endforeach</select></label>
                <input type="hidden" name="ends_on" data-asset-ends>
                <div class="field"><span class="label">Teslim edilen demirbaşlar (işaretli = tahsiste)</span><div style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach ($activeAssignments->flatMap->assets as $x)<label class="checkbox-row" style="margin:0"><input type="checkbox" name="asset_ids[]" value="{{ $x->id }}" data-asset-of="{{ $x->space_assignment_id }}"><span>{{ $x->name }}</span></label>@endforeach
                    @foreach ($assignableAssets as $x)<label class="checkbox-row" style="margin:0"><input type="checkbox" name="asset_ids[]" value="{{ $x->id }}"><span>{{ $x->name }}{{ $x->code ? ' ('.$x->code.')' : '' }} <span class="muted">· müsait</span></span></label>@endforeach
                </div></div>
                <p class="small muted" style="margin:0">İşareti kaldırılan demirbaş iade alınır (envanter kaydı).</p>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Vazgeç</button><button type="submit" class="btn btn--brand">Kaydet</button></div>
        </form>
    </dialog>
    @endif
    @endif

    @if ($can['payment'] || $can['contract'])
    <dialog class="modal" id="modal-document">
        <div class="modal__head"><h2>Belge oluştur</h2><button type="button" class="btn btn--quiet" data-modal-close>×</button></div>
        <div class="modal__body stack" style="gap:8px">
            <p class="small muted" style="margin:0">Belgeler mevcut belge motoruyla (şablon, PDF, yazdırma) üretilir.</p>
            @if ($can['contract'])@foreach ($contracts->where('status', '!=', 'ended') as $c)<form method="POST" action="{{ route('panel.members.contract.document', [$company->id, $member->id, $c->id]) }}">@csrf<button type="submit" class="btn btn--ghost" style="width:100%;justify-content:flex-start">Sözleşme belgesi · {{ $c->number }}</button></form>@endforeach @endif
            @if ($can['payment'])
                @foreach ($openInvoices->filter(fn ($i) => $i->daysOverdue() > 0) as $inv)<form method="POST" action="{{ route('panel.collections.invoices.notice', $inv->id) }}">@csrf<input type="hidden" name="return" value="{{ $returnUrl }}?sekme=belge"><button type="submit" class="btn btn--ghost" style="width:100%;justify-content:flex-start">Geciken ödeme belgesi · {{ $inv->number }}</button></form>@endforeach
                @foreach (collect($ledger)->where('type', 'payment')->where('cancelled', false)->where('document', null)->take(5) as $row)<div class="small muted">Makbuz: tahsilatı Finans › Hareket geçmişi satırından ya da <a href="{{ route('panel.collections.index') }}#tahsilatlar">Tahsilat merkezi</a>nden oluşturun.</div>@break @endforeach
            @endif
            @if ($contracts->where('status', '!=', 'ended')->isEmpty() && $openInvoices->filter(fn ($i) => $i->daysOverdue() > 0)->isEmpty())<div class="empty-state" style="border:0">Şu an üretilecek belge yok (aktif sözleşme ya da gecikmiş fatura gerekir).</div>@endif
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Kapat</button></div>
    </dialog>
    @endif
@endsection

@push('scripts')
    <script>
    // Demirbaş modalı: seçilen tahsise göre form action ve mevcut demirbaş işaretleri (bağımlılıksız; HTTP yok).
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-asset-form]'); if (!form) return;
        var sel = form.querySelector('[data-asset-assignment]'), ends = form.querySelector('[data-asset-ends]');
        function sync() {
            var o = sel.options[sel.selectedIndex]; if (!o) return;
            form.setAttribute('action', o.getAttribute('data-action')); ends.value = o.getAttribute('data-ends') || '';
            var current = (o.getAttribute('data-current') || '').split(',');
            form.querySelectorAll('[name="asset_ids[]"]').forEach(function (cb) { var of = cb.getAttribute('data-asset-of'); cb.closest('label').hidden = !!(of && of !== o.value); cb.checked = current.indexOf(cb.value) !== -1; });
        }
        sel.addEventListener('change', sync);
        document.querySelectorAll('[data-modal-open="#modal-asset"]').forEach(function (b) { b.addEventListener('click', function () { setTimeout(function () { try { var f = JSON.parse(b.getAttribute('data-fill') || '{}'); if (f.assignment_id) sel.value = String(f.assignment_id); } catch (e) {} sync(); }, 0); }); });
        sync();
    });
    </script>
@endpush
