<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SpaceService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Müşteri tarafı alanlar (space.view, company kapsamı): şirkete tahsisli masa/ofisler, salt okunur. */
class CompanySpaceController extends Controller
{
    public function __construct(private readonly SpaceService $spaces, private readonly TenantContext $context) {}

    public function index(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.companies.spaces', ['company' => $company, 'assignments' => $this->spaces->forCompany($company)]);
    }
}
