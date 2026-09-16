<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 29 — Kullanıcı yönetimi: personel daveti (şifre bağlantısı), global rol
 * atama/askıya alma, kendini kilitleme savunması, izin (user.manage).
 */
class UserAdminTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Notification::fake();
    }

    #[Test]
    public function personel_davet_edilir_rol_atanir_ve_askiya_alinir(): void
    {
        $admin = $this->staff('super_admin');

        $this->actingAs($admin)->get('/panel/kullanicilar')->assertOk()->assertSee($admin->email)->assertSee('Personel davet et');

        // Davet: kullanıcı yoksa oluşturulur + sıfırlama bağlantısı; şifre alanı yok.
        $this->actingAs($admin)->post('/panel/kullanicilar', ['name' => 'Ayşe Operasyon', 'email' => 'AYSE@ofisvio.com', 'role' => 'operations_admin'])->assertRedirect();
        $ayse = User::where('email', 'ayse@ofisvio.com')->firstOrFail();
        Notification::assertSentTo($ayse, ResetPassword::class);
        $role = UserRole::where('user_id', $ayse->id)->firstOrFail();
        $this->assertSame('operations_admin', $role->role->name);
        $this->assertNull($role->company_id);
        $this->assertSame('active', $role->status);

        // Müşteri rolü personel olarak atanamaz; bilinen personel rolü eklenir.
        $this->actingAs($admin)->from('/panel/kullanicilar/'.$ayse->id)->post("/panel/kullanicilar/{$ayse->id}/rol", ['role' => 'owner'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->post("/panel/kullanicilar/{$ayse->id}/rol", ['role' => 'finance_admin'])->assertRedirect();
        $this->assertSame(2, UserRole::where('user_id', $ayse->id)->count());

        // Askıya al -> etkinleştir; başka kullanıcının rol id'si ile 404.
        $this->actingAs($admin)->post("/panel/kullanicilar/{$ayse->id}/rol/{$role->id}/askiya-al")->assertRedirect();
        $this->assertSame('suspended', $role->fresh()->status);
        $this->actingAs($admin)->post("/panel/kullanicilar/{$admin->id}/rol/{$role->id}/etkinlestir")->assertNotFound();
        $this->actingAs($admin)->post("/panel/kullanicilar/{$ayse->id}/rol/{$role->id}/etkinlestir")->assertRedirect();
        $this->assertSame('active', $role->fresh()->status);

        // Yeniden davet (broker 60 sn kısıtlar; zaman ilerletilir).
        $this->travel(2)->minutes();
        $this->actingAs($admin)->post("/panel/kullanicilar/{$ayse->id}/davet")->assertRedirect();
        Notification::assertSentToTimes($ayse, ResetPassword::class, 2);

        // Var olan kullanıcıya davet: yalnız rol atanır, e-posta gitmez.
        $this->actingAs($admin)->post('/panel/kullanicilar', ['name' => 'Ayşe', 'email' => 'ayse@ofisvio.com', 'role' => 'reception'])->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentToTimes($ayse, ResetPassword::class, 2);
        $this->assertSame(3, UserRole::where('user_id', $ayse->id)->count());
    }

    #[Test]
    public function kendini_kilitleme_savunmasi_ve_izin(): void
    {
        $admin = $this->staff('super_admin');
        $ownRole = UserRole::where('user_id', $admin->id)->firstOrFail();

        // Kendi rolünü askıya alamaz; tek süper yönetici askıya alınamaz.
        $this->actingAs($admin)->from('/panel/kullanicilar/'.$admin->id)->post("/panel/kullanicilar/{$admin->id}/rol/{$ownRole->id}/askiya-al")->assertSessionHasErrors('role');
        $this->assertSame('active', $ownRole->fresh()->status);

        $second = $this->staff('super_admin');
        $secondRole = UserRole::where('user_id', $second->id)->firstOrFail();
        $this->actingAs($admin)->post("/panel/kullanicilar/{$second->id}/rol/{$secondRole->id}/askiya-al")->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($second)->post("/panel/kullanicilar/{$admin->id}/rol/{$ownRole->id}/askiya-al")->assertForbidden(); // askıdaki rolün yetkisi yok
        $sysadmin = $this->staff('system_admin'); // user.manage var
        $this->actingAs($sysadmin)->from('/panel/kullanicilar')->post("/panel/kullanicilar/{$admin->id}/rol/{$ownRole->id}/askiya-al")->assertSessionHasErrors('role'); // son aktif süper yönetici
        $this->assertSame('active', $ownRole->fresh()->status);

        // operations_admin (user.manage yok) ve müşteri sahibi giremez.
        $this->actingAs($this->staff('operations_admin'))->get('/panel/kullanicilar')->assertForbidden();
        $owner = $this->owner($this->organization('Acme'));
        $this->actingAs($owner)->get('/panel/kullanicilar')->assertForbidden();
        $this->actingAs($owner)->post('/panel/kullanicilar', ['name' => 'X', 'email' => 'x@x.com', 'role' => 'super_admin'])->assertForbidden();
    }
}
