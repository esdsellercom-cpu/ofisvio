<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Services\BookingService;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\ServiceService;
use App\Services\SiteBlockService;
use App\Services\SiteBuilderService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Vitrin ana sayfası.
 *
 * CLAUDE.md kuralı gereği controller'da iş mantığı yok; yalnızca yayınlanmış
 * lokasyonları okur ve görünüme hazırlar. Lokasyonlar TenantScope taşımaz
 * (Location tenant'a ait bir varlık değil, operatörün kendi şubesidir), bu
 * yüzden vitrin sayfası kimlik doğrulaması olmadan çalışır.
 */
class HomeController extends Controller
{
    private const DAY_NAMES = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

    public function __construct(
        private readonly ContentService $contents,
        private readonly SiteBlockService $blocks,
        private readonly CurrentWebsite $website,
        private readonly BookingService $bookings,
        private readonly SiteBuilderService $builder,
        private readonly ServiceService $services,
    ) {}

    public function __invoke(): View
    {
        return $this->render(false);
    }

    /**
     * İmzalı önizleme (route 'signed' middleware): taslak bölümler, noindex, önbellek yok.
     * `?editor=1` görsel editör çerçevesi (faz 49): tüm taslak bölümler + düzenleme işaretleri + şablonlar, editör betiği
     * yalnız burada yüklenir. `?revision=N` eski revizyonun görünümü (sürüm geçmişi önizlemesi).
     */
    public function preview(Request $request, int $website): View
    {
        abort_if($this->website->get()?->id !== $website, 404);

        if ($request->boolean('editor')) {
            $request->attributes->set('ofv.editor', true);
        }

        return $this->render(true, $request->boolean('editor'), $request->integer('revision') ?: null, $request->integer('preset') ?: null);
    }

    private function render(bool $preview, bool $editor = false, ?int $revision = null, ?int $preset = null): View
    {
        // Müşteri sitesi (Host eşleşti): Ofisvio pazarlama blokları DEĞİL,
        // o sitenin kendi sayfa/yazıları.
        if ($this->website->isTenantSite()) {
            $site = $this->website->get();

            return view('site.tenant-home', [
                'website' => $site,
                'pages' => $this->contents->livePages($site),
                'posts' => $this->contents->livePosts($site, 6),
            ]);
        }

        // Tek lokasyon modu (faz 53): bölge seçimi/boş kartlar yerine şube öne çıkar; metinler şehre göre. Tek şubede liste = o şube.
        $single = $this->blocks->singleLocation();
        $locations = $single !== null ? new Collection([$single->load(['cover', 'services'])]) : Location::published()->with(['cover', 'services'])->get();
        $site = $this->website->get();
        $sections = match (true) {
            $preset !== null => $this->builder->presetForPreview($site, $preset),
            $editor => $this->builder->draftForEditor($site),
            $revision !== null => $this->builder->revisionForPreview($site, $revision),
            $preview => $this->builder->draftForPreview($site),
            default => $this->builder->published($site),
        };
        $blocks = $this->blocks->all($site);

        // Önizleme/editör: global (footer sütunları) taslağı canlının üstüne biner; yayınlanana kadar vitrine çıkmaz.
        if ($preview) {
            $globals = $this->builder->globalsDraft($site);

            if ($globals['footer_columns'] !== '') {
                $blocks['footer_columns'] = $this->blocks->parseForPreview('footer_columns', $globals['footer_columns']);
            }

            foreach ($globals['blocks'] as $key => $text) {
                $blocks[$key] = $key === 'pricing_note' ? $text : $this->blocks->parseForPreview($key, $text);
            }
        }

        $rooms = $this->bookings->bookableRooms(true);
        $services = $this->services->active($this->website->get())->load('cover');

        return view('site.home', [
            'locations' => $locations,
            'regions' => $locations->groupBy('region'),
            'singleLocation' => $single,
            'stats' => $single !== null
                ? $this->singleStats($single, $services, $rooms, $this->blocks->texts($site))
                : $this->stats($locations, $this->blocks->texts($site)),
            'bookingDays' => $this->bookingDays(),
            // Rezervasyona açık gerçek odalar + onay politikasından türeyen rozet (booking engine v2).
            'bookableRooms' => $rooms,
            // Çözüm kartları: Hizmetler modülü (faz 4).
            'services' => $services,
            'bookingBadge' => $this->bookings->confirmationBadge(),
            // CMS: yayındaki son yazılar; yoksa bölüm gizlenir (uydurma metin yok).
            'homePosts' => $this->contents->livePosts($this->website->get(), 6),
            // Sayfa kurucu: yayınlanmış bölümler (önizlemede taslak).
            'sections' => $sections,
            'preview' => $preview,
            'editor' => $editor,
            'editorTemplates' => $editor ? $this->builder->templates($site) : [],
            'revisionPreview' => $revision,
            'presetPreview' => $preset,
            // Vitrin blokları: CMS kaydı varsa o, yoksa config varsayılanı (faz 10).
            'blocks' => $blocks,
        ]);
    }

    /**
     * İSTATİSTİKLER VERİTABANINDAN HESAPLANIR, elle yazılmaz.
     *
     * Tasarım mock'unda "18 lokasyon · 6 şehir" yazıyordu ama veride 14 lokasyon
     * ve 5 şehir var (İstanbul iki bölgeye ayrıldığı için bölge sayısı 6).
     * Sabit yazılmış bir rakam, şube açıldıkça sessizce yalan olur.
     *
     * Diğer pazarlama rakamları (üye sayısı, dönüş süresi, yenileme oranı)
     * ölçülebilir olmadıkları için BİLİNÇLİ OLARAK burada değil config'te
     * duruyor ve yayına çıkmadan doğrulanmaları gerekiyor.
     */
    /**
     * Önümüzdeki dört gün. Hafta sonu ELENMEZ: lokasyonlarda 7/24 kartlı geçiş
     * var, toplantı odası talebi hafta sonu da alınır.
     *
     * @return array<int, array{date: string, label: string, short: string}>
     */
    private function bookingDays(): array
    {
        $days = [];

        for ($offset = 0; $offset < 4; $offset++) {
            $date = Carbon::today()->addDays($offset);

            $days[] = [
                'date' => $date->toDateString(),
                'label' => match ($offset) {
                    0 => 'Bugün',
                    1 => 'Yarın',
                    default => self::DAY_NAMES[(int) $date->format('w')],
                },
                'short' => $date->format('d.m'),
            ];
        }

        return $days;
    }

    /**
     * Tek lokasyon modu: lokasyon/şehir/bölge sayımı anlamsızdır; şubenin gerçek verisi (hizmet, oda) sayılır.
     *
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, Room>  $rooms
     * @param  array<string, string>  $texts
     * @return list<array{value: string, label: string}>
     */
    private function singleStats(Location $location, Collection $services, Collection $rooms, array $texts): array
    {
        $offered = $location->services->where('is_active', true)->count() ?: $services->count();
        $roomCount = $rooms->where('location_id', $location->id)->count();

        return [
            ['value' => $offered > 0 ? (string) $offered : '', 'label' => 'Çözüm'],
            ['value' => $roomCount > 0 ? (string) $roomCount : '', 'label' => 'Toplantı odası'],
            ['value' => count((array) $location->opening_hours) > 0 ? 'Açık' : '', 'label' => $location->city.' · '.($location->district ?: 'merkez')],
            ['value' => (string) ($texts['stats_review_time'] ?? ''), 'label' => 'Belge inceleme süresi'],
        ];
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  array<string, string>  $texts
     * @return list<array{value: string, label: string}>
     */
    private function stats(Collection $locations, array $texts): array
    {
        return [
            ['value' => (string) $locations->count(), 'label' => 'Lokasyon'],
            ['value' => (string) $locations->pluck('city')->unique()->count(), 'label' => 'Şehir'],
            ['value' => (string) $locations->pluck('region')->unique()->count(), 'label' => 'Bölge'],
            // Ölçülemeyen tek kalem: panelden düzenlenir (Ana sayfa > metinler), kodda sabit değil.
            ['value' => (string) ($texts['stats_review_time'] ?? ''), 'label' => 'Belge inceleme süresi'],
        ];
    }
}
