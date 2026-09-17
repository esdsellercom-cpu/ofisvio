<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * İlk personel hesabı. Kayıt kapalı olduğu için (config/fortify.php) sistem
 * bu komut çalıştırılana kadar giriş yapılabilir tek bir hesap içermez —
 * seed edilmiş sabit şifre YOKTUR (§3: sahte veri yasağı, ve güvenlik).
 *
 * Verilen kullanıcı yoksa oluşturur, varsa yalnızca rol atar. Rol atama
 * GLOBAL'dir (üç kapsam kolonu NULL) — TenantContext::isInternalStaff ve
 * AuthorizationService'in "global" tanımı tam olarak budur.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'ofisvio:make-admin
                            {email : Personelin e-posta adresi}
                            {--name= : Ad Soyad (yeni hesapta zorunlu)}
                            {--role=super_admin : internal tipte rol adı (super_admin, system_admin, ...)}';

    protected $description = 'Global kapsamlı bir personel (internal rol) hesabı oluşturur ya da mevcut hesaba rol atar.';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $roleName = (string) $this->option('role');

        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null || $role->type !== 'internal') {
            $this->error("'{$roleName}' internal tipte bir rol değil ya da seed edilmemiş. Önce: php artisan db:seed --class=RolePermissionSeeder");

            return self::FAILURE;
        }

        if (validator(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            $this->error("Geçersiz e-posta: {$email}");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $name = (string) ($this->option('name') ?: text('Ad Soyad', required: true));
            $plain = password('Şifre (en az 12 karakter)', required: true, validate: fn (string $v) => strlen($v) < 12 ? 'En az 12 karakter.' : null);

            $user = DB::transaction(function () use ($name, $email, $plain) {
                $created = User::create(['name' => $name, 'email' => $email, 'password' => $plain]); // 'hashed' cast
                $created->forceFill(['email_verified_at' => now()])->save(); // komut satırından açıldı: adres doğrulanmış

                return $created;
            });

            $this->info("Kullanıcı oluşturuldu: {$email}");
        } else {
            $this->line("Kullanıcı zaten var: {$email} — yalnızca rol atanacak.");
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'company_id' => null,
            'organization_id' => null,
            'location_id' => null,
        ], ['status' => 'active']);

        $this->info("Global '{$roleName}' rolü atandı. Giriş: /login");

        return self::SUCCESS;
    }
}
