<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OffboardingService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Kullanıcı çıkışı (audit F-26): php artisan ofisvio:offboard <hedef e-posta> --by=<yetkili e-posta> --reason="..."
 * Roller askıya, JIT iptal, oturumlar düşer, hesap askıya; hepsi audit'te. Konsoldan koşar (panel dışı acil durum);
 * yetkili hesap süper yönetici olmalı.
 */
class OffboardCommand extends Command
{
    protected $signature = 'ofisvio:offboard {email : Çıkarılacak hesabın e-postası} {--by= : İşlemi yapan yetkilinin e-postası (süper yönetici)} {--reason=Ayrılış : Gerekçe (audit)}';

    protected $description = 'Hesabı çıkarır: roller askıya, JIT iptal, oturumlar kapanır, hesap askıya (audit).';

    public function handle(OffboardingService $offboarding): int
    {
        $target = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();
        $actor = User::query()->where('email', Str::lower(trim((string) $this->option('by'))))->first();

        if ($target === null || $actor === null) {
            $this->error('Hedef ya da yetkili hesap bulunamadı.');

            return self::FAILURE;
        }

        $isSuper = $actor->userRoles()->where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->exists();

        if (! $isSuper) {
            $this->error('--by ile verilen hesap aktif süper yönetici olmalı.');

            return self::FAILURE;
        }

        try {
            $summary = $offboarding->offboard($actor, $target, (string) $this->option('reason'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s çıkarıldı: %d rol askıya, %d JIT iptal, %d oturum kapatıldı, hesap askıda.', $target->email, $summary['roles'], $summary['grants'], $summary['sessions']));

        return self::SUCCESS;
    }
}
