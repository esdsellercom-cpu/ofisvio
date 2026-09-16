<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Müşteri organizasyonu açılışı — personel yolu.
 *
 * Serbest kayıt kapalı olduğu için (bkz. config/fortify.php) müşteri hesabı
 * ancak buradan doğar: personel organizasyonu açar, sahibini tanımlar.
 * Sahip için kullanıcı kaydı rastgele, kimsenin bilmediği bir şifreyle
 * oluşturulur ve standart şifre sıfırlama bağlantısı "davet" olarak
 * gönderilir — ayrı bir davet token'ı yazmak yerine denenmiş akış kullanılır.
 *
 * Aynı e-posta zaten kayıtlıysa yeni kullanıcı açılmaz; mevcut hesap üye
 * yapılır (bir kişi birden fazla organizasyona sahip olabilir).
 *
 * Yetki: user.manage (route'ta). Bu servis tenant context'i GEREKTİRMEZ:
 * organizasyon henüz yokken context de yoktur.
 */
class OrganizationOnboardingService
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly PasswordBroker $passwords,
    ) {}

    /**
     * @param  array{organization_name: string, owner_name: string, owner_email: string}  $data
     * @return array{organization: Organization, owner: User, invited: bool}
     */
    public function open(User $performedBy, array $data): array
    {
        $email = Str::lower(trim($data['owner_email']));

        $result = DB::transaction(function () use ($data, $email) {
            $organization = Organization::create([
                'name' => trim($data['organization_name']),
                'slug' => $this->uniqueSlug($data['organization_name']),
            ]);

            $owner = User::query()->where('email', $email)->first();
            $created = false;

            if ($owner === null) {
                $owner = User::create([
                    'name' => trim($data['owner_name']),
                    'email' => $email,
                    // Bilinmeyen, asla iletilmeyen şifre (User modelinin 'hashed'
                    // cast'i hash'ler); sahip kendi şifresini sıfırlama
                    // bağlantısıyla belirler.
                    'password' => Str::random(64),
                ]);
                $created = true;
            }

            OrganizationMember::query()->firstOrCreate([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
            ], ['status' => 'active']);

            $this->companies->assignOrganizationOwner($owner, $organization);

            return ['organization' => $organization, 'owner' => $owner, 'created' => $created];
        });

        // Davet e-postası transaction DIŞINDA: mail hatası kaydı geri almamalı,
        // personel bağlantıyı yeniden gönderebilir.
        $invited = false;

        if ($result['created']) {
            $invited = $this->passwords->sendResetLink(['email' => $email]) === PasswordBroker::RESET_LINK_SENT;
        }

        return ['organization' => $result['organization'], 'owner' => $result['owner'], 'invited' => $invited];
    }

    /** Organizasyon künyesi (organization.manage): yalnız ad; slug sabit (bağlamlar/loglar). */
    public function rename(Organization $organization, string $name): Organization
    {
        $organization->name = trim($name);
        $organization->save();

        return $organization;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organizasyon';
        $slug = $base;
        $i = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
