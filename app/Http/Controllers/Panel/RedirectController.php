<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\UrlRedirect;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\RedirectService;
use App\Services\UrlHistoryService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Yönlendirmeler & 404 merkezi (faz 54): manuel kayıtlar, onay bekleyen öneriler, 404 günlüğü, URL geçmişi ve
 * kırık URL / yönlendirme botu. Yetki route'ta (seo.view / seo.edit / seo.audit); veri servislerden.
 */
class RedirectController extends Controller
{
    public const TABS = ['yonlendirmeler' => 'Yönlendirmeler', 'oneriler' => 'Öneriler', '404' => '404 günlüğü', 'gecmis' => 'URL geçmişi', 'bot' => 'Kırık URL botu'];

    public function __construct(
        private readonly RedirectService $redirects,
        private readonly UrlHistoryService $history,
        private readonly ContentService $contents,
        private readonly AuthorizationService $authorization,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.redirects.index', $this->contents->defaultWebsite());
    }

    public function index(Request $request, Website $website, string $sekme = 'yonlendirmeler'): View
    {
        abort_unless(isset(self::TABS[$sekme]), 404);
        $user = $request->user();
        $status = (string) $request->query('durum', $sekme === '404' ? 'open' : 'all');
        $q = trim((string) $request->query('q', ''));

        return view('panel.seo.redirects', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'tab' => $sekme,
            'tabs' => self::TABS,
            'stats' => $this->redirects->stats($website),
            'status' => $status,
            'q' => $q,
            'rows' => $sekme === 'yonlendirmeler' ? $this->redirects->redirects($website, $status, $q) : null,
            'pending' => $sekme === 'oneriler' ? $this->redirects->pending($website) : null,
            'logs' => $sekme === '404' ? $this->redirects->notFoundLogs($website, $status, $q) : null,
            'history' => $sekme === 'gecmis' ? $this->redirects->recentHistory($website, 100) : null,
            'scan' => $sekme === 'bot' ? $this->redirects->lastScan($website) : null,
            'redirectMap' => $sekme === 'bot' ? $this->history->map($website) : [],
            'top' => $this->redirects->topRedirects($website),
            'canEdit' => $this->authorization->can($user, 'seo.edit'),
            'canAudit' => $this->authorization->can($user, 'seo.audit'),
            'editing' => $request->integer('duzenle') > 0 ? $this->redirects->find($website, $request->integer('duzenle')) : null,
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:300', 'regex:#^/[^\s]*$#'],
            'to_path' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#'],
            'code' => ['required', 'in:301,302,307,308'],
            'status' => ['required', 'in:active,disabled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->history->save($website, $data + ['source' => 'manual'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['from_path' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.seo.redirects.index', [$website, 'yonlendirmeler'])->with('status', 'Yönlendirme kaydedildi.');
    }

    public function update(Request $request, Website $website, UrlRedirect $redirect): RedirectResponse
    {
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:300', 'regex:#^/[^\s]*$#'],
            'to_path' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#'],
            'code' => ['required', 'in:301,302,307,308'],
            'status' => ['required', 'in:active,disabled,pending'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->assertOwned($website, $redirect);
            $this->history->save($website, $data + ['source' => $redirect->source], $request->user(), $redirect);
        } catch (DomainException $e) {
            return back()->withErrors(['from_path' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.seo.redirects.index', [$website, 'yonlendirmeler'])->with('status', 'Yönlendirme güncellendi.');
    }

    public function destroy(Request $request, Website $website, UrlRedirect $redirect): RedirectResponse
    {
        try {
            $this->history->delete($website, $redirect, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Yönlendirme silindi.');
    }

    /** Öneriyi onaylar (isteğe bağlı farklı hedef/kod) ya da reddeder (sil). */
    public function approve(Request $request, Website $website, UrlRedirect $redirect): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'to_path' => ['nullable', 'string', 'max:500', 'regex:#^(/|https?://)[^\s]*$#'],
            'code' => ['nullable', 'in:301,302,307,308'],
        ]);

        try {
            $this->assertOwned($website, $redirect);

            if ($data['decision'] === 'reject') {
                $this->redirects->reject($request->user(), $website, $redirect);

                return back()->with('status', 'Öneri reddedildi.');
            }

            $this->redirects->approve($request->user(), $website, $redirect, $data['to_path'] ?? null, isset($data['code']) ? (int) $data['code'] : null);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Yönlendirme etkinleştirildi.');
    }

    public function flatten(Request $request, Website $website, UrlRedirect $redirect): RedirectResponse
    {
        try {
            $this->assertOwned($website, $redirect);
            $this->redirects->flatten($request->user(), $website, $redirect);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Zincir düzleştirildi.');
    }

    public function notFoundStatus(Request $request, Website $website, int $log): RedirectResponse
    {
        $data = $request->validate(['durum' => ['required', 'in:ignore,open']]);

        try {
            $this->redirects->ignoreNotFound($request->user(), $website, $log, $data['durum'] === 'ignore');
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', $data['durum'] === 'ignore' ? 'Kayıt yok sayıldı.' : 'Kayıt yeniden açıldı.');
    }

    public function scan(Request $request, Website $website): RedirectResponse
    {
        $result = $this->redirects->runScan($request->user(), $website, $request->boolean('external'));

        return redirect()->route('panel.seo.redirects.index', [$website, 'bot'])->with('status', 'Tarama tamamlandı: '.$result['counts']['critical'].' kritik, '.$result['counts']['warning'].' uyarı, '.$result['counts']['suggestion'].' öneri.');
    }

    private function assertOwned(Website $website, UrlRedirect $redirect): void
    {
        if ((int) $redirect->website_id !== (int) $website->id) {
            throw new DomainException('Yönlendirme bu siteye ait değil.');
        }
    }
}
