<?php

namespace App\Http\Controllers\Panel;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateNavigationRequest;
use App\Models\Content;
use App\Models\Website;
use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\WebsiteService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * CMS editörü (F7). Varsayılan website'in içeriği; personel, global content.*
 * izinleriyle, tenant context'i olmadan. Her durum geçişi kendi route'u ve
 * izniyle (bkz. routes/panel.php):
 *
 *   submit    DRAFT     -> IN_REVIEW   content.edit
 *   reject    IN_REVIEW -> DRAFT       content.review
 *   approve   IN_REVIEW -> APPROVED    content.approve
 *   publish   *         -> PUBLISHED   content.publish
 *   schedule  *         -> SCHEDULED   content.schedule
 *   unpublish PUBLISHED -> DRAFT       content.publish
 *   archive   *         -> ARCHIVED    content.archive
 *   restore   ARCHIVED  -> DRAFT       content.edit
 *
 * Controller'da DB sorgusu yok; okuma/yazma ContentService'te.
 */
class ContentController extends Controller
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly WebsiteService $websites,
        private readonly ContentCache $cache,
        private readonly AuthorizationService $authorization,
    ) {}

    /** ?website=<id> ile seçilen site; yoksa varsayılan (Ofisvio vitrini). */
    private function selectedWebsite(Request $request): Website
    {
        $id = $request->integer('website');

        return $id > 0 ? $this->contents->websiteById($id) : $this->contents->defaultWebsite();
    }

    public function index(Request $request): View
    {
        $kind = ContentKind::tryFrom((string) $request->query('kind', ''));
        $status = ContentStatus::tryFrom((string) $request->query('status', ''));
        $website = $this->selectedWebsite($request);

        return view('panel.content.index', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'items' => $this->contents->listFor($website, $kind, $status, (string) $request->query('q', '')),
            'q' => (string) $request->query('q', ''),
            'kind' => $kind,
            'status' => $status,
            'kinds' => ContentKind::cases(),
            'statuses' => ContentStatus::cases(),
        ]);
    }

    /** İçerik takvimi (faz 24): ?ay=YYYY-MM, varsayılan bu ay. */
    public function calendar(Request $request): View
    {
        $website = $this->selectedWebsite($request);
        $tz = config('app.timezone');
        $ay = (string) $request->query('ay', '');
        $hafta = (string) $request->query('hafta', '');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ay) === 1
            ? CarbonImmutable::parse($ay.'-01', $tz)->startOfMonth()
            : CarbonImmutable::now($tz)->startOfMonth();

        // Haftalık görünüm: ?hafta=YYYY-MM-DD (haftanın herhangi bir günü); bozuk değer aylık görünüme düşer.
        $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hafta) === 1 && strtotime($hafta) !== false
            ? CarbonImmutable::parse($hafta, $tz)->startOfWeek()
            : null;

        if ($week !== null) {
            $month = $week->startOfMonth();
        }

        return view('panel.content.calendar', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'month' => $month,
            'week' => $week,
            'days' => $week !== null
                ? $this->contents->calendarRange($website, $week, $week->endOfWeek())
                : $this->contents->calendar($website, $month),
            'pipeline' => $this->contents->pipeline($website),
            'workload' => $this->contents->workload($website),
            'today' => CarbonImmutable::now($tz)->format('Y-m-d'),
        ]);
    }

    /** Site menüsü (faz 10): ?website=<id>. */
    public function menu(Request $request): View
    {
        $website = $this->selectedWebsite($request);

        return view('panel.content.menu', [
            'website' => $website,
            'pages' => $this->contents->pagesFor($website),
            'formAction' => route('panel.content.menu.update', $website),
            'themeAction' => route('panel.content.theme', $website),
            'linksAction' => route('panel.content.links', $website),
            'backUrl' => route('panel.content.index', ['website' => $website->id]),
            'backLabel' => 'İçerik',
        ]);
    }

    public function saveLinks(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate(['links' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->websites->updateNavLinks($website, (string) ($validated['links'] ?? ''));
        } catch (DomainException $e) {
            return back()->withErrors(['links' => $e->getMessage()])->withInput();
        }

        $this->cache->invalidate($website);

        return redirect()->route('panel.content.menu', ['website' => $website->id])->with('status', 'Bağlantılar kaydedildi.');
    }

    public function saveTheme(Request $request, Website $website): RedirectResponse
    {
        $validated = $request->validate(['theme' => ['required', 'string', Rule::in(array_keys((array) config('ofisvio.themes')))]]);

        $this->websites->updateTheme($website, $validated['theme']);
        $this->cache->invalidate($website);

        return redirect()->route('panel.content.menu', ['website' => $website->id])->with('status', 'Tema uygulandı.');
    }

    public function saveMenu(UpdateNavigationRequest $request, Website $website): RedirectResponse
    {
        $this->contents->updateNavigation($website, $request->layout());

        return redirect()->route('panel.content.menu', ['website' => $website->id])->with('status', 'Menü kaydedildi.');
    }

    public function create(Request $request): View
    {
        $website = $this->selectedWebsite($request);

        return view('panel.content.form', [
            'content' => null,
            'website' => $website,
            'kind' => ContentKind::tryFrom((string) $request->query('kind', 'post')) ?? ContentKind::POST,
            'parents' => $this->contents->parentCandidates($website),
        ]);
    }

    public function store(StoreContentRequest $request): RedirectResponse
    {
        try {
            $website = $this->contents->websiteById((int) $request->validated('website_id'));
            $content = $this->contents->create($request->user(), $website, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.show', $content)->with('status', 'Taslak oluşturuldu.');
    }

    public function show(Request $request, Content $content): View
    {
        $user = $request->user();

        return view('panel.content.show', [
            'content' => $content,
            'revisions' => $this->contents->revisionsOf($content),
            'suggestions' => $this->contents->linkSuggestions($content),
            'can' => [
                'edit' => $this->authorization->can($user, 'content.edit'),
                'review' => $this->authorization->can($user, 'content.review'),
                'approve' => $this->authorization->can($user, 'content.approve'),
                'publish' => $this->authorization->can($user, 'content.publish'),
                'schedule' => $this->authorization->can($user, 'content.schedule'),
                'archive' => $this->authorization->can($user, 'content.archive'),
            ],
        ]);
    }

    public function edit(Content $content): View|RedirectResponse
    {
        if ($content->status !== ContentStatus::DRAFT) {
            return redirect()->route('panel.content.show', $content)
                ->withErrors(['status' => 'Yalnızca taslak düzenlenir; önce taslağa alın.']);
        }

        return view('panel.content.form', ['content' => $content, 'website' => $content->website, 'kind' => $content->kind, 'parents' => $this->contents->parentCandidates($content->website, $content)]);
    }

    public function update(StoreContentRequest $request, Content $content): RedirectResponse
    {
        try {
            $this->contents->update($request->user(), $content, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.content.show', $content)->with('status', 'Kaydedildi (yeni revizyon).');
    }

    /** Silme (soft delete, content.archive): yalnız taslak/arşiv. */
    public function destroy(Request $request, Content $content): RedirectResponse
    {
        $website = $content->website;

        try {
            $this->contents->delete($request->user(), $content);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.index', ['website' => $website->id, 'kind' => $content->kind->value])->with('status', 'İçerik silindi.');
    }

    // --- Durum geçişleri -------------------------------------------------

    public function submit(Request $request, Content $content): RedirectResponse
    {
        return $this->move($request, $content, ContentStatus::IN_REVIEW, 'İncelemeye gönderildi.');
    }

    public function reject(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::DRAFT, 'Taslağa geri gönderildi; yazar notu görecek.');
    }

    public function approve(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::APPROVED, 'Onaylandı; yayınlanabilir.');
    }

    public function publish(Request $request, Content $content): RedirectResponse
    {
        return $this->move($request, $content, ContentStatus::PUBLISHED, 'Yayınlandı.');
    }

    public function unpublish(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::DRAFT, 'Yayından kaldırıldı; taslak olarak düzenlenebilir.');
    }

    public function schedule(Request $request, Content $content): RedirectResponse
    {
        $validated = $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        return $this->move(
            $request,
            $content,
            ContentStatus::SCHEDULED,
            'Zamanlandı.',
            Carbon::parse($validated['scheduled_for'], config('app.timezone')),
        );
    }

    public function archive(Request $request, Content $content): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->move($request, $content, ContentStatus::ARCHIVED, 'Arşivlendi.');
    }

    public function restore(Request $request, Content $content): RedirectResponse
    {
        return $this->move($request, $content, ContentStatus::DRAFT, 'Taslağa alındı.');
    }

    private function move(Request $request, Content $content, ContentStatus $target, string $message, ?Carbon $scheduledFor = null): RedirectResponse
    {
        try {
            $this->contents->transition($request->user(), $content, $target, $request->input('note'), $scheduledFor);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.content.show', $content)->with('status', $message);
    }
}
