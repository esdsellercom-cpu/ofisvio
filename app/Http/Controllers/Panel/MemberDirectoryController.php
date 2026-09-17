<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\UserRole;
use App\Services\CompanyService;
use App\Services\MembershipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Üyeler & kullanıcılar (faz 39, artifact §5): aktif organizasyondaki şirketlerin üyeleri
 * tek dizinde. Görünürlük CompanyService::visibleTo (personel hepsi, müşteri company.view
 * taşıdığı şirketler); yönetim şirketin üye ekranında (membership.manage).
 */
class MemberDirectoryController extends Controller
{
    public function __construct(private readonly CompanyService $companies, private readonly MembershipService $members) {}

    public function index(Request $request): View
    {
        $companies = $this->companies->visibleTo($request->user());
        $members = $this->members->membersOfCompanies($companies);
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        if ($q !== '') {
            $members = $members->filter(fn (UserRole $m) => str_contains(mb_strtolower($m->user->name.' '.$m->user->email), $q))->values();
        }

        return view('panel.members.directory', [
            'members' => $members,
            'q' => $q,
            'counts' => [
                'companies' => $companies->count(),
                'members' => $members->count(),
                'active' => $members->filter(fn (UserRole $m) => $m->isActive())->count(),
                'people' => $members->pluck('user_id')->unique()->count(),
            ],
        ]);
    }
}
