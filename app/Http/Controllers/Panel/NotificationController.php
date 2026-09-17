<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Integrations\SecretStore;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Notifications\NotificationEvents;
use App\Services\GeoService;
use App\Services\NotificationService;
use App\Services\UserAdminService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bildirim merkezi (master prompt §16–18): kurallar (olay × kanal × grup), alıcılar
 * (DB'de; kodda telefon yok), şablonlar, gönderim günlüğü; gelen kutusu (uygulama içi).
 * notification.view görür, notification.manage yazar.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly GeoService $geo,
        private readonly UserAdminService $users,
        private readonly SecretStore $secrets,
    ) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('sekme', 'kurallar');
        $event = (string) $request->query('olay', array_key_first(NotificationEvents::registry()));
        $channel = (string) $request->query('kanal', 'whatsapp');

        if (! NotificationEvents::exists($event)) {
            $event = (string) array_key_first(NotificationEvents::registry());
        }

        if (! isset(NotificationEvents::CHANNELS[$channel])) {
            $channel = 'whatsapp';
        }

        $logFilters = $request->validate(['durum' => ['nullable', Rule::in(array_keys(NotificationLog::STATUSES))], 'kanal' => ['nullable', 'string'], 'olay' => ['nullable', 'string']]);

        return view('panel.notifications.index', [
            'tab' => in_array($tab, ['kurallar', 'alicilar', 'sablonlar', 'gunluk'], true) ? $tab : 'kurallar',
            'events' => NotificationEvents::registry(),
            'channels' => NotificationEvents::CHANNELS,
            'groups' => NotificationEvents::GROUPS,
            'matrix' => $this->notifications->rulesMatrix(),
            'recipients' => $this->notifications->recipients(),
            'locations' => $this->geo->allLocations(),
            'staff' => $this->users->staff(),
            'template' => $this->notifications->template($event, $channel),
            'templateEvent' => $event,
            'templateChannel' => $channel,
            'logs' => $tab === 'gunluk' ? $this->notifications->logs(['status' => $logFilters['durum'] ?? null, 'channel' => $logFilters['kanal'] ?? null, 'event' => $logFilters['olay'] ?? null]) : null,
            'logFilters' => $logFilters,
            'counts' => $this->notifications->counts(),
            'providers' => [
                'whatsapp' => $this->secrets->enabled('whatsapp') && $this->secrets->missing('whatsapp') === [],
                'sms' => $this->secrets->enabled('sms') && $this->secrets->missing('sms') === [],
                'email' => (string) config('mail.default'),
            ],
        ]);
    }

    public function saveRules(Request $request): RedirectResponse
    {
        $raw = (array) $request->input('rules', []);
        $matrix = [];

        foreach (NotificationEvents::registry() as $event => $def) {
            foreach (NotificationEvents::CHANNELS as $channel => $l) {
                foreach (NotificationEvents::GROUPS as $group => $gl) {
                    $matrix[$event][$channel][$group] = ! empty($raw[$event][$channel][$group]);
                }
            }
        }

        $this->notifications->saveRules($request->user(), $matrix);

        return redirect()->route('panel.notifications.index', ['sekme' => 'kurallar'])->with('status', 'Bildirim kuralları kaydedildi.');
    }

    public function storeRecipient(Request $request): RedirectResponse
    {
        return $this->persistRecipient($request, null);
    }

    public function updateRecipient(Request $request, NotificationRecipient $recipient): RedirectResponse
    {
        return $this->persistRecipient($request, $recipient);
    }

    /** Aktif/pasif: adres HTML'ye çıkmadan (PII) yalnız durum değişir. */
    public function toggleRecipient(Request $request, NotificationRecipient $recipient): RedirectResponse
    {
        $this->notifications->setRecipientActive($request->user(), $recipient, ! $recipient->is_active);

        return redirect()->route('panel.notifications.index', ['sekme' => 'alicilar'])->with('status', $recipient->is_active ? 'Alıcı etkinleştirildi.' : 'Alıcı pasife alındı.');
    }

    public function destroyRecipient(Request $request, NotificationRecipient $recipient): RedirectResponse
    {
        $this->notifications->deleteRecipient($request->user(), $recipient);

        return redirect()->route('panel.notifications.index', ['sekme' => 'alicilar'])->with('status', 'Alıcı silindi.');
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'event' => ['required', 'string'], 'channel' => ['required', Rule::in(array_keys(NotificationEvents::CHANNELS))],
            'locale' => ['required', Rule::in(['tr', 'en'])], 'subject' => ['nullable', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $this->notifications->saveTemplate($request->user(), $v['event'], $v['channel'], $v['locale'], $v['subject'] ?? null, (string) ($v['body'] ?? ''));
        } catch (DomainException|\InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.notifications.index', ['sekme' => 'sablonlar', 'olay' => $v['event'], 'kanal' => $v['channel']])->with('status', 'Şablon kaydedildi.');
    }

    /** Gelen kutusu: kullanıcının uygulama içi bildirimleri (herkes; yalnız kendi). */
    public function inbox(Request $request): View
    {
        $user = $request->user();

        return view('panel.notifications.inbox', [
            'notifications' => $user->notifications()->latest()->paginate(30),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request): RedirectResponse
    {
        $id = (string) $request->input('id', '');
        $user = $request->user();

        if ($id === '') {
            $user->unreadNotifications()->update(['read_at' => now()]);
        } else {
            $user->unreadNotifications()->where('id', $id)->update(['read_at' => now()]);
        }

        return redirect()->route('panel.notifications.inbox');
    }

    private function persistRecipient(Request $request, ?NotificationRecipient $recipient): RedirectResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'channel' => ['required', Rule::in(array_keys(NotificationEvents::CHANNELS))],
            'address' => ['nullable', 'string', 'max:190'],
            'user_id' => ['nullable', 'integer'],
            'group' => ['required', Rule::in(array_keys(NotificationEvents::GROUPS))],
            'location_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->notifications->saveRecipient($request->user(), [
                'name' => $v['name'], 'channel' => $v['channel'], 'address' => $v['address'] ?? null,
                'user_id' => isset($v['user_id']) ? (int) $v['user_id'] : null, 'group' => $v['group'],
                'location_id' => isset($v['location_id']) ? (int) $v['location_id'] : null, 'is_active' => $request->boolean('is_active'),
            ], $recipient);
        } catch (DomainException $e) {
            return back()->withErrors(['recipient' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.notifications.index', ['sekme' => 'alicilar'])->with('status', $recipient ? 'Alıcı güncellendi.' : 'Alıcı eklendi.');
    }
}
