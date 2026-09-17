<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\BookingService;
use App\Services\ContentService;
use App\Services\EventService;
use App\Services\GeoService;
use App\Services\MediaService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Etkinlikler & topluluk (faz 39d, artifact §9): event.view listeler/katılımcıları görür,
 * event.manage açar/düzenler/yayınlar/kayıt durumu değiştirir. Kapak medya kütüphanesinden.
 */
class EventController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly GeoService $geo,
        private readonly BookingService $bookings,
        private readonly MediaService $media,
        private readonly ContentService $contents,
    ) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('sekme', 'upcoming');

        if (! isset(EventService::TABS[$tab])) {
            $tab = 'upcoming';
        }

        return view('panel.events.index', ['events' => $this->events->all($tab), 'tab' => $tab, 'tabs' => EventService::TABS, 'stats' => $this->events->dashboard()]);
    }

    public function create(): View
    {
        return view('panel.events.form', ['event' => null] + $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $event = $this->events->create($request->user(), $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['ends_at' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.events.show', $event)->with('status', $event->title.' oluşturuldu'.($event->is_published ? ' ve yayınlandı.' : ' (taslak).'));
    }

    public function show(Event $event): View
    {
        return view('panel.events.show', ['event' => $this->events->find($event->slug) ?? abort(404), 'statuses' => EventRegistration::STATUSES]);
    }

    public function edit(Event $event): View
    {
        return view('panel.events.form', ['event' => $event] + $this->formData());
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        try {
            $this->events->update($request->user(), $event, $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['ends_at' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.events.show', $event)->with('status', $event->title.' güncellendi.');
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        try {
            $this->events->delete($request->user(), $event);
        } catch (DomainException $e) {
            return back()->withErrors(['event' => $e->getMessage()]);
        }

        return redirect()->route('panel.events.index')->with('status', 'Etkinlik silindi.');
    }

    public function registration(Request $request, Event $event, EventRegistration $registration): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(EventRegistration::STATUSES))]]);

        try {
            $this->events->setRegistrationStatus($request->user(), $registration, $data['status']);
        } catch (DomainException $e) {
            return back()->withErrors(['registration' => $e->getMessage()]);
        }

        return back()->with('status', 'Kayıt durumu güncellendi.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'locations' => $this->geo->allLocations(),
            'rooms' => $this->bookings->allRooms(),
            'mediaOptions' => $this->media->all($this->contents->defaultWebsite()),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'location_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_published' => ['nullable', 'boolean'],
            'registration_open' => ['nullable', 'boolean'],
            'cover_media_id' => ['nullable', 'integer'],
        ]) + ['is_published' => $request->boolean('is_published'), 'registration_open' => $request->boolean('registration_open')];
    }
}
