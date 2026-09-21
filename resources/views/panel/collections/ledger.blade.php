@extends('layouts.panel')

@section('title', 'Muhasebe defteri')

@section('content')
    <div class="panel-head">
        <div>
            <p class="eyebrow"><a href="{{ route('panel.collections.index') }}">Tahsilat</a> · defter</p>
            <h1 class="h2">Muhasebe defteri (append-only)</h1>
            <p>Fatura kesimi, tahsilat, tahsilat iptali ve fatura iptali her biri yeni satırdır; satırlar güncellenmez ve silinmez. Yanlış kayıt yeni bir düzeltme satırıyla kapatılır. Tutar fatura bakiyesi açısından imzalıdır (+ borç, − alacak); "kalan" fatura üzerindeki bakiye anlık görüntüsüdür.</p>
        </div>
    </div>
    <form method="GET" class="inline-form" style="margin-bottom:12px;gap:8px;flex-wrap:wrap">
        <select class="control" name="tur" style="max-width:220px" data-autosubmit><option value="">Tüm türler</option>@foreach ($types as $k => $label)<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach</select>
        <noscript><button type="submit" class="btn btn--ghost btn--pill">Süz</button></noscript>
    </form>
    @if ($canCorrect)
        <details class="panel" style="margin-bottom:12px">
            <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Düzeltme kaydı ekle (JIT: ledger.correction_entry — fatura başına gerekçeli, süreli erişim)</summary>
            <div class="grid-auto" style="--min:280px;--gap:14px;margin-top:10px">
                <form method="POST" action="#" class="stack" style="gap:8px" data-jit-form data-no-busy>
                    @csrf
                    <p class="eyebrow" style="margin:0">1. Erişim aç</p>
                    <label class="field"><span class="label">Fatura no (id)</span><input class="control mono" type="number" name="invoice_id" min="1" required data-jit-invoice></label>
                    <label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="reason" maxlength="300" required></label>
                    <label class="field"><span class="label">Süre (dk)</span><input class="control" type="number" name="ttl_minutes" value="15" min="5" max="120"></label>
                    <div><button type="submit" class="btn btn--ghost btn--pill" data-jit-submit data-route="{{ route('panel.collections.ledger.jit', ['invoice' => 0]) }}">Erişim iste</button></div>
                </form>
                <form method="POST" action="#" class="stack" style="gap:8px" data-jit-form data-no-busy>
                    @csrf
                    <p class="eyebrow" style="margin:0">2. Düzeltme satırı</p>
                    <label class="field"><span class="label">Fatura no (id)</span><input class="control mono" type="number" name="invoice_id" min="1" required data-jit-invoice></label>
                    <label class="field"><span class="label">Tutar ({{ money_symbol() }})</span><input class="control mono" type="text" name="amount" inputmode="decimal" required placeholder="0,00"></label>
                    <label class="field"><span class="label">Yön</span><select class="control" name="direction"><option value="debit">Borç (+)</option><option value="credit">Alacak (−)</option></select></label>
                    <label class="field"><span class="label">Gerekçe</span><input class="control" type="text" name="memo" maxlength="200" required></label>
                    <div><button type="submit" class="btn btn--brand btn--pill" data-jit-submit data-route="{{ route('panel.collections.ledger.correction', ['invoice' => 0]) }}">Düzeltme ekle</button></div>
                </form>
            </div>
        </details>
    @endif
    <div class="table-wrap"><table class="data">
        <thead><tr><th>#</th><th>Tarih</th><th>Firma</th><th>Fatura</th><th>Tür</th><th style="text-align:right">Tutar</th><th style="text-align:right">Kalan</th><th>Açıklama</th><th>Kim</th></tr></thead>
        <tbody>
            @forelse ($entries as $e)
                <tr>
                    <td class="mono small">{{ $e->id }}</td>
                    <td class="small">{{ $e->created_at->format('d.m.Y H:i') }}</td>
                    <td class="small">{{ $e->company?->legal_name ?? '—' }}</td>
                    <td class="mono small">@if ($e->invoice)<a href="{{ route('panel.invoices.show', $e->invoice) }}">{{ $e->invoice->number ?? '#'.$e->invoice->id }}</a>@else —@endif</td>
                    <td><span class="badge badge--{{ $e->amount < 0 ? 'ok' : ($e->type === 'correction' ? 'warn' : 'info') }}">{{ $types[$e->type] ?? $e->type }}</span></td>
                    <td class="mono" style="text-align:right">{{ $e->amount < 0 ? '−' : '+' }}{{ money(abs($e->amount), $e->currency) }}</td>
                    <td class="mono small" style="text-align:right">{{ $e->balance_after !== null ? money($e->balance_after, $e->currency) : '—' }}</td>
                    <td class="small">{{ $e->memo }}</td>
                    <td class="small">{{ $e->author?->name ?? 'sistem' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="body-muted">Kayıt yok.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:12px">{{ $entries->links() }}</div>
@endsection
