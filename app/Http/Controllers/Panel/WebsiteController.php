<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Website;
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
    public function __construct(
        private readonly WebsiteService $websites,
        private readonly ContextSwitchService $switcher,
    ) {}

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
