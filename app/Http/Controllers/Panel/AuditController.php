<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Denetim kaydı (audit.view). Salt okunur; sekme ?tur=general|jit|context|company|webhook|integration. */
class AuditController extends Controller
{
    public function __construct(private readonly AuditLogService $audit, private readonly AuditService $general) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'tur' => ['nullable', Rule::in(array_keys(AuditLogService::TYPES))],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $type = $filters['tur'] ?? 'jit';

        return view('panel.audit.index', [
            'type' => $type,
            'types' => AuditLogService::TYPES,
            'filters' => $filters,
            'counts' => $this->audit->counts(),
            'rows' => match ($type) {
                'context' => $this->audit->contextSwitches($filters),
                'company' => $this->audit->companyTransitions($filters),
                'webhook' => $this->audit->webhooks($filters),
                'integration' => $this->audit->integrations($filters),
                'general' => $this->general->paginate($filters),
                default => $this->audit->jitGrants($filters),
            },
        ]);
    }
}
