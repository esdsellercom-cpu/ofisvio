<?php

namespace App\Http\Controllers\Panel;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentRequest;
use App\Models\Content;
use App\Services\ContentService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Çalışma taslağı (faz 18): yayındaki içerik canlıda kalırken kopyası
 * düzenlenir, aynı inceleme/onay akışından geçer, yayınlanınca birleşir.
 *
 *   open     PUBLISHED içerik için taslak aç      content.edit
 *   edit/update  taslağı düzenle (yalnız DRAFT)   content.edit
 *   submit   DRAFT -> IN_REVIEW                   content.edit
 *   reject   IN_REVIEW -> DRAFT (not zorunlu)     content.review
 *   approve  IN_REVIEW -> APPROVED                content.approve
 *   publish  birleştir + revizyon + önbellek      content.publish
 *   schedule zamanla (zamanı gelince birleşir)    content.schedule
 *   restore  SCHEDULED/IN_REVIEW -> DRAFT         content.edit | content.schedule
 *   discard  taslağı sil                          content.edit
 *
 * Taslak içerikle bire birdir ({content} üzerinden erişilir); controller'da DB sorgusu yok.
 */
class ContentDraftController extends Controller
{
    public function __construct(private readonly ContentService $contents) {}

    public function open(Request $request, Content $content): RedirectResponse
    {
        try {
            $this->contents->openDraft($request->user(), $content);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.draft.edit', $content)->with('status', 'Çalışma taslağı açıldı; yayındaki metin değişmedi.');
    }

    public function edit(Content $content): View|RedirectResponse
    {
        $draft = $content->draft;

        if ($draft === null) {
            return redirect()->route('panel.content.show', $content)->withErrors(['status' => 'Bu içeriğin çalışma taslağı yok.']);
        }

        if ($draft->status !== ContentStatus::DRAFT) {
            return redirect()->route('panel.content.show', $content)->withErrors(['status' => 'Taslak incelemede; düzenlemek için önce geri gönderilmeli.']);
        }

        return view('panel.content.form', ['content' => $content, 'draft' => $draft, 'website' => $content->website, 'kind' => $content->kind]);
    }

    public function update(StoreContentRequest $request, Content $content): RedirectResponse
    {
        $draft = $content->draft;

        if ($draft === null) {
            return redirect()->route('panel.content.show', $content)->withErrors(['status' => 'Bu içeriğin çalışma taslağı yok.']);
        }

        try {
            $this->contents->updateDraft($request->user(), $draft, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.show', $content)->with('status', 'Çalışma taslağı kaydedildi.');
    }

    public function submit(Request $request, Content $content): RedirectResponse
    {
        return $this->move($request, $content, ContentStatus::IN_REVIEW, 'Taslak incelemeye gönderildi.');
    }

    public function reject(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::DRAFT, 'Taslak geri gönderildi; yazar notu görecek.');
    }

    public function approve(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::APPROVED, 'Taslak onaylandı; birleştirilebilir.');
    }

    public function publish(Request $request, Content $content): RedirectResponse
    {
        $draft = $content->draft;

        if ($draft === null) {
            return back()->withErrors(['status' => 'Bu içeriğin çalışma taslağı yok.']);
        }

        try {
            $this->contents->publishDraft($request->user(), $draft);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.show', $content)->with('status', 'Taslak yayındaki metinle birleştirildi.');
    }

    public function schedule(Request $request, Content $content): RedirectResponse
    {
        $validated = $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        return $this->move($request, $content, ContentStatus::SCHEDULED, 'Taslak zamanlandı; zamanı gelince yayındaki metinle birleşir.', Carbon::parse($validated['scheduled_for'], config('app.timezone')));
    }

    /** Zamanlamayı iptal et ya da incelemedeki taslağı geri al: taslak durumuna döner. */
    public function restore(Request $request, Content $content): RedirectResponse
    {
        return $this->move($request, $content, ContentStatus::DRAFT, 'Taslak düzenlenebilir duruma alındı.');
    }

    public function discard(Content $content): RedirectResponse
    {
        $draft = $content->draft;

        if ($draft !== null) {
            $this->contents->discardDraft($draft);
        }

        return redirect()->route('panel.content.show', $content)->with('status', 'Çalışma taslağı silindi; yayındaki metin olduğu gibi kaldı.');
    }

    private function move(Request $request, Content $content, ContentStatus $target, string $message, ?Carbon $scheduledFor = null): RedirectResponse
    {
        $draft = $content->draft;

        if ($draft === null) {
            return back()->withErrors(['status' => 'Bu içeriğin çalışma taslağı yok.']);
        }

        try {
            $this->contents->transitionDraft($request->user(), $draft, $target, $request->input('note'), $scheduledFor);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.show', $content)->with('status', $message);
    }
}
