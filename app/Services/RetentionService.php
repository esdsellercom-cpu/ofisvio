<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\KycDocumentStatus;
use App\Models\Booking;
use App\Models\EventRegistration;
use App\Models\FranchiseApplication;
use App\Models\KycDocument;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * KVKK saklama / imha (audit F-09). Amaçla sınırlı saklama: süresi dolan vitrin kayıtlarının kişisel alanları
 * ANONİMLEŞTİRİLİR (satır ve rıza kaydı kalır — istatistik/kanıt bozulmaz, kimlik gider); yerine yenisi yüklenmiş ya da
 * karantinadaki KYC dosyaları diskten SİLİNİR (satır kalır, `purged_at`). Süreler `privacy.*` ayarlarından; 0 = kapalı.
 * Aktif ilişkiler dokunulmaz: yalnız sonuçlanmış (terminal) kayıtlar. Her koşu audit'e adet yazar; --dry-run yalnız sayar.
 * Yalnız bu servis anonimleştirir.
 */
class RetentionService
{
    public const ANON_EMAIL = 'silindi@anonim.invalid';

    public function __construct(private readonly SettingsService $settings, private readonly AuditService $audit) {}

    /** @return array<string, int> kategori => işlenen kayıt */
    public function run(bool $dryRun = false): array
    {
        $counts = [
            'leads' => $this->leads($dryRun),
            'bookings' => $this->bookings($dryRun),
            'franchise_applications' => $this->franchise($dryRun),
            'event_registrations' => $this->events($dryRun),
            'kyc_files' => $this->kycFiles($dryRun),
        ];

        if (! $dryRun && array_sum($counts) > 0) {
            $this->audit->record(null, 'privacy.retention_run', 'system', null, [], $counts);
        }

        return $counts;
    }

    private function cutoff(string $key): ?Carbon
    {
        $months = $this->settings->int($key);

        return $months > 0 ? Carbon::now()->subMonths($months) : null;
    }

    private function leads(bool $dryRun): int
    {
        $cutoff = $this->cutoff('privacy.lead_retention_months');

        if ($cutoff === null) {
            return 0;
        }

        $query = Lead::query()->whereNull('anonymized_at')->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $n = 0;

        foreach ($query->cursor() as $lead) {
            $lead->forceFill(['name' => 'Anonim', 'email' => self::ANON_EMAIL, 'phone' => null, 'note' => null, 'consent_ip' => null, 'consent_user_agent' => null, 'internal_note' => null, 'anonymized_at' => now()])->save();
            $n++;
        }

        return $n;
    }

    private function bookings(bool $dryRun): int
    {
        $cutoff = $this->cutoff('privacy.booking_retention_months');

        if ($cutoff === null) {
            return 0;
        }

        $terminal = array_map(fn (BookingStatus $s) => $s->value, array_filter(BookingStatus::cases(), fn (BookingStatus $s) => $s->isTerminal()));
        // Yalnız vitrin (şirketsiz) rezervasyonlar: müşteri şirketinin kayıtları sözleşme/fatura ilişkisi taşır.
        $query = Booking::withoutTenantScope()->whereNull('anonymized_at')->whereNull('company_id')->whereIn('status', $terminal)->where('ends_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $n = 0;

        foreach ($query->cursor() as $booking) {
            $booking->forceFill(['customer_name' => 'Anonim', 'customer_email' => self::ANON_EMAIL, 'customer_phone' => null, 'company_name' => null, 'note' => null, 'consent_ip' => null, 'anonymized_at' => now()])->save();
            $n++;
        }

        return $n;
    }

    private function franchise(bool $dryRun): int
    {
        $cutoff = $this->cutoff('privacy.franchise_retention_months');

        if ($cutoff === null) {
            return 0;
        }

        $query = FranchiseApplication::query()->whereNull('anonymized_at')->whereIn('status', ['negative', 'archived'])->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $n = 0;

        foreach ($query->cursor() as $application) {
            $application->forceFill(['name' => 'Anonim', 'first_name' => 'Anonim', 'last_name' => '', 'email' => self::ANON_EMAIL, 'phone' => null, 'message' => null, 'internal_note' => null, 'consent_ip' => null, 'anonymized_at' => now()])->save();
            $n++;
        }

        return $n;
    }

    private function events(bool $dryRun): int
    {
        $cutoff = $this->cutoff('privacy.event_registration_retention_months');

        if ($cutoff === null) {
            return 0;
        }

        $query = EventRegistration::query()->whereNull('anonymized_at')->where('created_at', '<', $cutoff)
            ->whereHas('event', fn ($q) => $q->where('starts_at', '<', $cutoff));

        if ($dryRun) {
            return $query->count();
        }

        $n = 0;

        foreach ($query->cursor() as $registration) {
            $registration->forceFill(['name' => 'Anonim', 'email' => self::ANON_EMAIL, 'phone' => null, 'company_name' => null, 'note' => null, 'consent_ip' => null, 'anonymized_at' => now()])->save();
            $n++;
        }

        return $n;
    }

    /** Yerine yenisi yüklenmiş / reddedilmiş / karantinadaki KYC dosyaları: dosya silinir, satır (durum, karar, audit) kalır. */
    private function kycFiles(bool $dryRun): int
    {
        $cutoff = $this->cutoff('privacy.kyc_superseded_retention_months');

        if ($cutoff === null) {
            return 0;
        }

        $query = KycDocument::withoutTenantScope()->whereNull('purged_at')
            ->whereIn('status', [KycDocumentStatus::SUPERSEDED->value, KycDocumentStatus::REJECTED->value, KycDocumentStatus::QUARANTINED->value])
            ->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $n = 0;

        foreach ($query->cursor() as $document) {
            $path = (string) $document->storage_path;

            if ($path !== '' && Storage::disk('private')->exists($path)) {
                Storage::disk('private')->delete($path);
            }

            $document->forceFill(['purged_at' => now()])->save();
            $n++;
        }

        return $n;
    }
}
