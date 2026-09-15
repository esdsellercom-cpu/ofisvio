# Kurulum kayıtları

## 1. Middleware (Laravel 11/12 — bootstrap/app.php)

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'tenant'     => \App\Http\Middleware\EnsureTenantContext::class,
        'permission' => \App\Http\Middleware\EnsurePermission::class,
    ]);
})
```

Laravel 10: `app/Http/Kernel.php` içindeki `$middlewareAliases` dizisine ekleyin.

## 2. Provider (bootstrap/providers.php)

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthorizationServiceProvider::class,
];
```

Laravel 10: `config/app.php` içindeki `providers` dizisine ekleyin.

## 3. User modeline trait (app/Models/User.php)

```php
use App\Models\Concerns\HasTenantRoles;

class User extends Authenticatable
{
    use HasTenantRoles;   // <- tek eklenecek satır
}
```

## 4. Private disk (config/filesystems.php)

KYC belgeleri **public erişilebilir olmamalıdır**. `disks` dizisine:

```php
'private' => [
    'driver' => 'local',
    'root'   => storage_path('app/private'),
    // 'url' ve 'visibility' KASITLI OLARAK YOK: bu disk için public URL
    // üretilememeli. Belgeye erişim yalnızca KycController::show()
    // üzerinden, JIT kapısından geçerek olur.
    'throw'  => false,
],
```

Üretimde S3 kullanıyorsanız bucket'ın **public erişime tamamen kapalı**
olduğundan ve pre-signed URL sürelerinin kısa tutulduğundan emin olun.

## 5. Migration ve seed

```bash
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan test
```

---

# Yeni modül yazarken uyulacak kalıp

KYC modülü referanstır. Yeni bir modül yazarken:

1. **Route**: `->middleware('permission:<izin>,<kapsam>')` — yetki burada,
   controller'da değil.
2. **Controller**: DB sorgusu YOK. `TenantContext::toArray()` ile doğrulanmış
   context al, servisi çağır, cevabı biçimlendir.
3. **Service**: tüm iş kuralları ve sorgular burada.
4. **Model**: tenant'a ait modele `use BelongsToTenant`.
5. **Enum**: durum alanları string değil enum; geçişler enum içinde tanımlı.
6. **Test**: "izin verilmemeli" senaryoları "izin verilmeli"den daha önemlidir.

## Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Controller'da `Model::where(...)` | Tenant scope'a güvenilir, RBAC atlanır |
| `$company->status = ...` doğrudan atama | State machine baypas, geçiş audit'i kaybolur |
| `withoutTenantScope()` gerekçesiz kullanımı | Cross-tenant sızıntı |
| `can()` çağırıp `allows()` çağırmamak | JIT kapısı atlanır |
| Form request `authorize()` içinde yetki kontrolü | Yetki iki yerde; biri unutulunca sessizce açılır |

`grep -rn withoutTenantScope app/` komutu tenant savunmasını atlayan tüm
yerleri listeler — code review'da bu listeye bakın.
