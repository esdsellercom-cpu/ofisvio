<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadService;
use App\Services\UserAdminService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Talepler (CRM v1). index/show lead.view; update lead.assign. Global personel
 * izinleri (matris crm modülü); talep hiçbir organizasyona ait değildir.
 */
class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly UserAdminService $users,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['quote', 'booking'])],
            'status' => ['nullable', Rule::in(array_keys(Lead::STATUSES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return view('panel.leads.index', [
            'leads' => $this->leads->paginate($filters),
            'filters' => $filters,
            'statuses' => Lead::STATUSES,
        ]);
    }

    public function show(Lead $lead): View
    {
        return view('panel.leads.show', [
            'lead' => $lead->load(['location', 'assignee']),
            'statuses' => Lead::STATUSES,
            'staff' => $this->users->staff(),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', Rule::in($this->users->staff()->pluck('id')->all())],
            'status' => ['required', Rule::in(array_keys(Lead::STATUSES))],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->leads->update($lead, isset($validated['assigned_to']) ? (int) $validated['assigned_to'] : null, $validated['status'], $validated['internal_note'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('panel.leads.show', $lead)->with('status', 'Talep güncellendi.');
    }
}
