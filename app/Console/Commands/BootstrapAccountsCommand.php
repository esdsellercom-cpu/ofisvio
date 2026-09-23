<?php

namespace App\Console\Commands;

use App\Install\PasswordPolicy;
use App\Models\Company;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyService;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ortam değişkeninden hesap açılışı (audit §28-29).
 *
 *   OFISVIO_ADMIN_EMAIL / OFISVIO_ADMIN_PASSWORD           -> super_admin (her ortam)
 *   OFISVIO_TEST_CUSTOMER_EMAIL / OFISVIO_TEST_CUSTOMER_PASSWORD -> owner + organizasyon + şirket
 *                                                             (YALNIZ production dışı)
 *
 * Şifre kaynak kodda ve seeder'da yoktur; env boşsa hesap açılmaz (uyarı).
 * Değerler config('ofisvio.accounts') üzerinden okunur (config:cache uyumlu).
 * Var olan kullanıcının şifresi yalnız --reset-password ile değiştirilir.
 * Şifre politikası: en az 16 karakter, büyük/küçük harf, rakam, özel karakter.
 */
class BootstrapAccountsCommand extends Command
{
    protected $signature = 'ofisvio:bootstrap-accounts {--reset-password : Var olan hesabın şifresini env değeriyle değiştir}';

    protected $description = 'OFISVIO_ADMIN_* ve (production dışında) OFISVIO_TEST_CUSTOMER_* env değerlerinden hesapları açar.';

    public function handle(CompanyService $companies, TenantContext $context): int
    {
        $adminOk = $this->admin();
        $customerOk = $this->testCustomer($companies, $context);

        return $adminOk && $customerOk ? self::SUCCESS : self::FAILURE;
    }

    private function admin(): bool
    {
        $email = Str::lower(trim((string) config('ofisvio.accounts.admin_email', '')));
        $password = (string) config('ofisvio.accounts.admin_password', '');

        if ($email === '') {
            $this->warn('OFISVIO_ADMIN_EMAIL boş — yönetici açılmadı.');

            return true;
        }

        if (! $this->passwordOk($password)) {
            $this->error('OFISVIO_ADMIN_PASSWORD politikaya uymuyor (≥16 karakter, büyük/küçük harf, rakam, özel karakter).');

            return false;
        }

        $role = Role::query()->where('name', 'super_admin')->where('type', 'internal')->first();

        if ($role === null) {
            $this->error('super_admin rolü yok — önce db:seed.');

            return false;
        }

        $user = $this->upsertUser($email, 'Sistem Yöneticisi', $password);

        UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id, 'company_id' => null, 'organization_id' => null, 'location_id' => null],
            ['status' => 'active'],
        );

        $this->info("Yönetici hazır: {$email} (super_admin). İlk girişte 2FA kurulumu zorunlu.");

        return true;
    }

    private function testCustomer(CompanyService $companies, TenantContext $context): bool
    {
        $email = Str::lower(trim((string) config('ofisvio.accounts.test_customer_email', '')));
        $password = (string) config('ofisvio.accounts.test_customer_password', '');

        if ($email === '') {
            return true;
        }

        if (config('app.env') === 'production') {
            $this->error('Test müşterisi production ortamına açılmaz (OFISVIO_TEST_CUSTOMER_* env değerlerini kaldırın).');

            return false;
        }

        if (! $this->passwordOk($password)) {
            $this->error('OFISVIO_TEST_CUSTOMER_PASSWORD politikaya uymuyor.');

            return false;
        }

        $user = $this->upsertUser($email, 'Test Müşterisi', $password);

        DB::transaction(function () use ($user, $companies, $context): void {
            $organization = Organization::query()->firstOrCreate(['slug' => 'test-musterisi'], ['name' => 'Test Müşterisi Org.']);

            OrganizationMember::query()->firstOrCreate(['organization_id' => $organization->id, 'user_id' => $user->id], ['status' => 'active']);
            $companies->assignOrganizationOwner($user, $organization);

            $company = $context->runAsSystem(fn () => Company::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'legal_name' => 'Test Müşterisi Ltd. Şti.'],
            ));

            $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();
            UserRole::query()->firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $ownerRole->id, 'company_id' => $company->id, 'organization_id' => null, 'location_id' => null],
                ['status' => 'active'],
            );
        });

        $this->info("Test müşterisi hazır: {$email} (owner, Test Müşterisi Org. / Ltd. Şti.).");

        return true;
    }

    private function upsertUser(string $email, string $name, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save(); // operatör açtı: adres doğrulanmış (fillable değil, bilinçli)

            return $user;
        }

        if ($this->option('reset-password')) {
            $user->password = $password;
            $user->save();
            $this->line("{$email}: şifre env değeriyle yenilendi.");
        }

        return $user;
    }

    private function passwordOk(string $password): bool
    {
        return PasswordPolicy::ok($password); // tek kaynak: web kurulum sihirbazı da aynı eşiği uygular
    }
}
