<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFranchiseApplicationRequest;
use App\Services\CurrentWebsite;
use App\Services\FranchiseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** Franchise başvurusu (faz 39e): form + kayıt — yalnız varsayılan (Ofisvio) sitede. */
class FranchiseController extends Controller
{
    public function __construct(private readonly FranchiseService $franchise, private readonly CurrentWebsite $website) {}

    public function show(): View
    {
        abort_if($this->website->isTenantSite(), 404);

        return view('site.franchise');
    }

    public function store(StoreFranchiseApplicationRequest $request): RedirectResponse
    {
        abort_if($this->website->isTenantSite(), 404);
        $this->franchise->apply($request->validated(), ['ip' => $request->ip()]);

        return redirect()->route('site.franchise')->with('franchise_sent', true);
    }
}
