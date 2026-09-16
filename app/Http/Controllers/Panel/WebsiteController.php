<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\ContextSwitchService;
use App\Services\WebsiteService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Website yönetimi (faz 10). Yetki route'ta: website.view (liste),
 * website.manage (aç/düzenle). Personel, tenant context'i olmadan.
 */
class WebsiteController extends Controller
{
    /** Site genel ayarları (faz 29) — müşteri paneli (SiteController) aynı kuralları kullanır. */
    public const SETTINGS_RULES = [
        'contact_phone' => ['nullable', 'string', 'max:32'],
        'contact_email' => ['nullable', 'email:rfc', 'max:190'],
        'tagline' => ['nullable', 'string', 'max:200'],
        'address' => ['nullable', 'string', 'max:300'],
    ];

    public function __construct(
        private readonly WebsiteService $websites,
        private readonly ContextSwitchService $switcher,
        private readonly ContentCache $cache,
    ) {}

    /** website.manage: soft delete — yalnız içeriksiz, varsayılan olmayan site. */
    public function destroy(Website $website): RedirectResponse
    {
        try {
            $this->websites->delete($website);
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        $this->cache->invalidate($website);

        return redirect()->route('panel.websites.index')->with('status', $website->name.' silindi.');
    }

    /** Site genel ayarları: iletişim/kimlik alanları; vitrin önbelleği sürüm atlar. */
    public function settings(Request $request, Website $website): RedirectResponse
    {
        $this->websites->updateSettings($website, $request->validate(self::SETTINGS_RULES));
        $this->cache->invalidate($website);

        return redirect()->route('panel.websites.edit', $website)->with('status', $website->name.' site ayarları güncellendi.');
    }

    public function index(): View
    {
        return view('panel.websites.index', ['websites' => $this->websites->all()]);
    }

    public function create(Request $request): View
    {
        return view('panel.websites.form', [
            'website' => null,
            'organizations' => $this->switcher->enterableOrganizations($request->user()),
        ]);
    }

    public function store(StoreWebsiteRequest $request): RedirectResponse
    {
        try {
            $website = $this->websites->create($request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.websites.index')->with('status', $website->name.' açıldı.');
    }

    public function edit(Request $request, Website $website): View
    {
        return view('panel.websites.form', [
            'website' => $website,
            'organizations' => $this->switcher->enterableOrganizations($request->user()),
        ]);
    }

    public function update(StoreWebsiteRequest $request, Website $website): RedirectResponse
    {
        try {
            $this->websites->update($website, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['organization_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.websites.index')->with('status', $website->name.' güncellendi.');
    }
}
