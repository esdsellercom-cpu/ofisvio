<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Raporlar & analitik (faz 39): analytics.view; sekme başına yalnız gereken toplamlar. */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('sekme', 'gelir');

        if (! isset(ReportService::TABS[$tab])) {
            $tab = 'gelir';
        }

        return view('panel.reports.index', [
            'tab' => $tab,
            'tabs' => ReportService::TABS,
            'data' => $this->reports->tab($request->user(), $tab),
        ]);
    }
}
