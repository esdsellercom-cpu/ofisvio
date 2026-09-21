<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\UserRole;
use App\Services\JitAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Audit F-26: çıkış komutu roller askıya, açık JIT iptal, oturumlar kapanır, hesap askıya; giriş reddedilir; son süper
 * yönetici çıkarılamaz; kişi kendini çıkaramaz; her adım audit'te.
 */
class OffboardTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function cikis_rolleri_jit_ve_oturumlari_kapatir_hesabi_askiya_alir(): void
    {
        $this->seedRbac();
        $super = $this->staff('super_admin');
        $ops = $this->staff('operations_admin');
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme Ltd');
        $this->assertNotNull(app(JitAccessService::class)->grant($ops, 'booking.admin_override', [], 'room', 1, 'acil'));
        config(['session.driver' => 'database']); // oturum sayımı yalnız database sürücüsünde
        DB::table('sessions')->insert(['id' => 'sess-ops', 'user_id' => $ops->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'phpunit', 'payload' => '', 'last_activity' => time()]);

        $this->artisan('ofisvio:offboard', ['email' => $ops->email, '--by' => $ops->email])->assertFailed(); // yetkili süper yönetici olmalı
        $this->artisan('ofisvio:offboard', ['email' => $super->email, '--by' => $super->email])->assertFailed(); // kendini çıkaramaz
        $code = Artisan::call('ofisvio:offboard', ['email' => $ops->email, '--by' => $super->email, '--reason' => 'İşten ayrıldı']);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('1 rol askıya, 1 JIT iptal, 1 oturum kapatıldı', $out);

        $this->assertSame(0, UserRole::query()->where('user_id', $ops->id)->where('status', 'active')->count());
        $this->assertSame([], app(JitAccessService::class)->activeGrantsFor($ops));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $ops->id)->count());
        $this->assertSame('suspended', $ops->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'user.offboarded')->where('entity_id', $ops->id)->exists());
        $this->actingAs($ops->fresh())->get('/panel')->assertRedirect(); // askıdaki hesap panele giremez

        // İkinci süper yönetici çıkarılabilir; kalan tek süper yönetici korunur (yetkili kendisi olamayacağından komutla erişilemez, servis kuralı UserAdminService::suspendRole).
        $other = $this->staff('super_admin');
        $this->artisan('ofisvio:offboard', ['email' => $other->email, '--by' => $super->email])->assertSuccessful();
        $this->assertSame(1, UserRole::query()->where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->count());
    }
}
