<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\PanelSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Üst çubuk araması (faz 38). Route: auth + staff.2fa; tenant bağlamı şart değil
 * (şirket kümesi yalnız bağlam varsa). Yetki kümeler bazında serviste.
 */
class SearchController extends Controller
{
    public function __construct(private readonly PanelSearchService $search) {}

    public function __invoke(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('panel.search', [
            'q' => $q,
            'results' => $this->search->search($request->user(), $q),
        ]);
    }
}
