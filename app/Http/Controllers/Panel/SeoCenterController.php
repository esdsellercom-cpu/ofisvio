<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Seo\HealthCenter;
use App\Seo\SchemaInspector;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\JitAccessService;
use App\Services\SeoSettingsService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SEO & GEO Command Center (faz 60): sağlık merkezi + Schema Manager.
 *
 *   center        seo.view      — 16 kategori, bulgu tablosu, süzgeçler
 *   decide        seo.edit      — yok say / çözüldü / yeniden aç
 *   fix           seo.edit      — "edit" modlu otomatik düzeltme (onaylı)
 *   fixCritical   seo.settings + JIT — "critical" modlu düzeltme (canonical, sitemap)
 *   schema        seo.view      — sayfa → JSON-LD önizleme + doğrulama
 */
class SeoCenterController extends Controller
{
    public function __construct(
        private readonly HealthCenter $center,
        private readonly SchemaInspector $inspector,
        private readonly ContentService $contents,
        private readonly SeoSettingsService $settings,
        private readonly AuthorizationService $authorization,
        private readonly JitAccessService $jit,
    ) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('panel.seo.center', $this->contents->defaultWebsite());
    }

    public function center(Request $request, Website $website): View
    {
        $user = $request->user();
        $report = $this->center->report($website);
        $category = (string) $request->query('kategori', '');
        $status = (string) $request->query('durum', SeoIssue::STATUS_OPEN);
        $severity = (string) $request->query('onem', '');
        $issues = array_values(array_filter($report['issues'], fn (array $i) => ($category === '' || $i['category'] === $category)
            && ($status === 'all' || $i['status'] === $status)
            && ($severity === '' || $i['severity'] === $severity)));

        return view('panel.seo.center', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'report' => $report,
            'issues' => $issues,
            'filters' => ['kategori' => $category, 'durum' => $status, 'onem' => $severity],
            'categories' => HealthCenter::CATEGORIES,
            'severities' => HealthCenter::SEVERITIES,
            'canEdit' => $this->authorization->can($user, 'seo.edit'),
            'canCritical' => $this->jit->hasActiveGrant($user, 'seo.settings', SeoController::RESOURCE, $website->id),
            'canRequestJit' => $this->authorization->can($user, 'seo.settings'),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
            'settings' => $this->settings->for($website),
        ]);
    }

    public function decide(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'size:40', 'regex:/^[a-f0-9]{40}$/'],
            'status' => ['required', Rule::in(SeoIssue::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->center->decide($request->user(), $website, $data['key'], $data['status'], $data['note'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', match ($data['status']) {
            SeoIssue::STATUS_IGNORED => 'Bulgu yok sayıldı.',
            SeoIssue::STATUS_RESOLVED => 'Bulgu çözüldü olarak işaretlendi; bir sonraki taramada yeniden görülürse açılır.',
            default => 'Bulgu yeniden açıldı.',
        });
    }

    public function fix(Request $request, Website $website): RedirectResponse
    {
        return $this->applyFix($request, $website, 'edit');
    }

    public function fixCritical(Request $request, Website $website): RedirectResponse
    {
        return $this->applyFix($request, $website, 'critical');
    }

    private function applyFix(Request $request, Website $website, string $mode): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Düzeltmeyi uygulamak için onay kutusunu işaretleyin.']);

        try {
            $label = $this->center->autofix($request->user(), $website, $data['key'], $mode);
        } catch (DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', 'Uygulandı: '.$label);
    }

    public function schemaHome(): RedirectResponse
    {
        return redirect()->route('panel.seo.schema', $this->contents->defaultWebsite());
    }

    public function schema(Request $request, Website $website): View
    {
        $path = trim((string) $request->query('yol', ''));
        $inspection = $path !== '' ? $this->inspector->inspect($website, $path) : null;

        return view('panel.seo.schema', [
            'website' => $website,
            'websites' => $this->contents->allWebsites(),
            'pages' => $this->inspector->pages($website),
            'path' => $path,
            'inspection' => $inspection,
            'notFound' => $path !== '' && $inspection === null,
            'settings' => $this->settings->for($website),
        ]);
    }
}
