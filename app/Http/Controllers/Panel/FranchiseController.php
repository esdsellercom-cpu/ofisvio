<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\FranchiseApplication;
use App\Services\FranchiseService;
use App\Services\UserAdminService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Franchise yönetimi (faz 39e): franchise.view listeler, franchise.manage durum/atama/not yazar. */
class FranchiseController extends Controller
{
    public function __construct(private readonly FranchiseService $franchise, private readonly UserAdminService $users) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(FranchiseApplication::STATUSES))], 'q' => ['nullable', 'string', 'max:120']]);

        return view('panel.franchise.index', [
            'rows' => $this->franchise->paginate($filters),
            'filters' => $filters,
            'counts' => $this->franchise->counts(),
            'statuses' => FranchiseApplication::STATUSES,
        ]);
    }

    public function show(FranchiseApplication $application): View
    {
        return view('panel.franchise.show', ['app' => $application->load('assignee'), 'statuses' => FranchiseApplication::STATUSES, 'staff' => $this->users->staff()]);
    }

    public function update(Request $request, FranchiseApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(FranchiseApplication::STATUSES))],
            'assigned_to' => ['nullable', 'integer', Rule::in($this->users->staff()->pluck('id')->all())],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->franchise->update($request->user(), $application, $data['status'], isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, $data['internal_note'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Başvuru güncellendi.');
    }
}
