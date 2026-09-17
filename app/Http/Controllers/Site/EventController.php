<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Services\CurrentWebsite;
use App\Services\EventService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** Etkinlikler (faz 39d): liste, detay, kayıt — yalnız varsayılan (Ofisvio) sitede. */
class EventController extends Controller
{
    public function __construct(private readonly EventService $events, private readonly CurrentWebsite $website) {}

    public function index(): View
    {
        abort_if($this->website->isTenantSite(), 404);

        return view('site.events', ['events' => $this->events->upcoming($this->website->get())]);
    }

    public function show(string $slug): View
    {
        abort_if($this->website->isTenantSite(), 404);
        $event = $this->events->findPublished($slug);
        abort_if($event === null, 404);

        return view('site.event', ['event' => $event]);
    }

    public function register(StoreEventRegistrationRequest $request, string $slug): RedirectResponse
    {
        abort_if($this->website->isTenantSite(), 404);
        $event = $this->events->findPublished($slug);
        abort_if($event === null, 404);

        try {
            $this->events->register($event, $request->validated(), ['ip' => $request->ip()]);
        } catch (DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }

        return redirect()->to($event->path().'#kayit')->with('event_registered', true);
    }
}
