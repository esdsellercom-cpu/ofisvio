<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\BookingService;
use App\Services\ContentService;
use App\Services\CurrentWebsite;
use App\Services\SiteBlockService;
use App\Support\ActivationJourney;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
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
    ) {}

    public function __invoke(): View
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

        $locations = Location::published()->get();

        return view('site.home', [
            'locations' => $locations,
            'regions' => $locations->groupBy('region'),
            'stats' => $this->stats($locations, $this->blocks->texts($this->website->get())),
            'journey' => ActivationJourney::steps(),
            'bookingDays' => $this->bookingDays(),
            // Rezervasyona açık gerçek odalar + onay politikasından türeyen rozet (booking engine v2).
            'bookableRooms' => $this->bookings->bookableRooms(true),
            'bookingBadge' => $this->bookings->confirmationBadge(),
            // CMS: yayındaki son yazılar; yoksa bölüm gizlenir (uydurma metin yok).
            'posts' => $this->contents->livePosts($this->website->get(), 3),
            // Vitrin blokları: CMS kaydı varsa o, yoksa config varsayılanı (faz 10).
            'blocks' => $this->blocks->all($this->website->get()),
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
