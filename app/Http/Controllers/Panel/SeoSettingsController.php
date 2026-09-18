<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Seo\SeoSettingsRegistry;
use App\Services\AuthorizationService;
use App\Services\ContentService;
use App\Services\JitAccessService;
use App\Services\SeoService;
use App\Services\SeoSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SEO & GEO gelişmiş ayarları (faz 44): site başına sekmeli form. Sekmenin modu hangi rotayla
 * yazılacağını belirler (registry TABS): edit → seo.edit, critical → seo.settings + JIT,
 * integration → seo.integrations + JIT, entity → geo.settings + JIT. Yanlış moda gönderilen
 * sekme 404 (izin atlatma yok). Doğrulama ve kayıt SeoSettingsService'te.
 */
class SeoSettingsController extends Controller
{
    public function __construct(
        private readonly SeoSettingsService $settings,
        private readonly SeoService $seo,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
        private readonly ContentService $contents,
    ) {}

    /** Menü girişi (faz 60): varsayılan sitenin istenen sekmesine. */
    public function home(Request $request): RedirectResponse
    {
        $tab = (string) $request->query('sekme', 'tarama');

        return redirect()->route('panel.seo.settings.show', [$this->contents->defaultWebsite(), SeoSettingsRegistry::tabExists($tab) ? $tab : 'tarama']);
    }

    public function show(Request $request, Website $website, string $sekme = 'tarama'): View
    {
        if (! SeoSettingsRegistry::tabExists($sekme)) {
            abort(404);
        }

        $user = $request->user();
        $mode = SeoSettingsRegistry::mode($sekme);
        [$permission, $resource] = self::gate($mode);
        $canEdit = $mode === 'edit'
            ? $this->authorization->can($user, 'seo.edit')
            : $this->jit->hasActiveGrant($user, $permission, $resource, $website->id);

        return view('panel.seo.settings', [
            'website' => $website,
            'tab' => $sekme,
            'tabs' => SeoSettingsRegistry::TABS,
            'mode' => $mode,
            'definitions' => SeoSettingsRegistry::forTab($sekme),
            'values' => $this->settings->formData($website, $sekme),
            'all' => $this->settings->for($website),
            'canEdit' => $canEdit,
            'canRequestJit' => $mode !== 'edit' && ! $canEdit && $this->authorization->can($user, $permission),
            'jitScope' => match ($mode) {
                'integration' => 'integrations', 'entity' => 'entity', default => 'settings'
            },
            'action' => route('panel.seo.settings.'.match ($mode) {
                'edit' => 'edit', 'critical' => 'critical', 'integration' => 'integration', default => 'entity'
            }, [$website, $sekme]),
            'report' => $sekme === 'teknik' ? $this->seo->technicalReport($website) : [],
            'robotsPreview' => $sekme === 'tarama' ? $this->seo->robotsTxt($website) : null,
            'llmsPreview' => $sekme === 'geo' ? $this->seo->llmsTxt($website) : null,
            'security' => $sekme === 'guvenlik' ? self::securitySummary() : [],
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function updateEdit(Request $request, Website $website, string $sekme): RedirectResponse
    {
        return $this->save($request, $website, $sekme, 'edit');
    }

    public function updateCritical(Request $request, Website $website, string $sekme): RedirectResponse
    {
        return $this->save($request, $website, $sekme, 'critical');
    }

    public function updateIntegration(Request $request, Website $website, string $sekme): RedirectResponse
    {
        return $this->save($request, $website, $sekme, 'integration');
    }

    public function updateEntity(Request $request, Website $website, string $sekme): RedirectResponse
    {
        return $this->save($request, $website, $sekme, 'entity');
    }

    private function save(Request $request, Website $website, string $sekme, string $expectedMode): RedirectResponse
    {
        if (! SeoSettingsRegistry::tabExists($sekme) || SeoSettingsRegistry::mode($sekme) !== $expectedMode) {
            abort(404);
        }

        $this->settings->updateTab($request->user(), $website, $sekme, $request->all());

        return redirect()->route('panel.seo.settings.show', [$website, $sekme])->with('status', SeoSettingsRegistry::TABS[$sekme]['label'].' ayarları kaydedildi.');
    }

    /** @return array{0: string, 1: string} izin, JIT kaynak tipi */
    public static function gate(string $mode): array
    {
        return match ($mode) {
            'critical' => ['seo.settings', SeoController::RESOURCE],
            'integration' => ['seo.integrations', SeoController::RESOURCE],
            'entity' => ['geo.settings', GeoController::RESOURCE],
            default => ['seo.edit', ''],
        };
    }

    /**
     * Güvenlik sekmesi özeti: uygulama genelinde verilen başlıklar (SecurityHeaders) — salt okunur.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function securitySummary(): array
    {
        $cfg = (array) config('ofisvio.security');

        return [
            ['label' => 'HTTPS / HSTS', 'value' => ($cfg['hsts'] ?? false) ? 'HSTS açık (yalnız https yanıtlarında)' : 'HSTS kapalı (SECURITY_HSTS ile açılır; üretimde doctor uyarır)'],
            ['label' => 'Content-Security-Policy', 'value' => "default-src 'self'; script/style satır içi serbest; img https:; frame-ancestors vitrinde 'self', panelde 'none'. GA4/GTM tanımlıysa Google kökenleri eklenir."],
            ['label' => 'Referrer-Policy', 'value' => 'strict-origin-when-cross-origin'],
            ['label' => 'X-Content-Type-Options', 'value' => 'nosniff'],
            ['label' => 'X-Frame-Options', 'value' => 'vitrin SAMEORIGIN · panel DENY'],
            ['label' => 'XML-RPC / gereksiz uç nokta', 'value' => 'Yok — yalnız routes/site.php ve routes/panel.php rotaları; /up sağlık ucu içerik döndürmez.'],
        ];
    }
}
