# Ofisvio

Sanal ofis, hazır ofis ve coworking için çok kiracılı yönetim paneli ve vitrin.
Laravel 13 · PHP 8.3 · SQLite (geliştirme) · Vite 8.

## Kurulum

```
composer install
npm ci
cp .env.example .env          # Windows: copy
php artisan key:generate
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=LocationSeeder
php artisan db:seed --class=WebsiteSeeder
php artisan ofisvio:make-admin sen@ornek.com --name="Ad Soyad"
php artisan serve
```

- Vitrin: `http://127.0.0.1:8000`
- Panel girişi: `http://127.0.0.1:8000/login`
- Geliştirmede e-postalar `storage/logs/laravel.log`'a yazılır (`MAIL_MAILER=log`);
  davet/şifre bağlantısı oradan alınır.

Kayıt formu yoktur: müşteri organizasyonunu personel açar (Panel → Yeni müşteri),
sahibi e-postayla davet edilir; sahip şirket açar ve üyelerini davet eder.

## Kalite kapısı (§76)

Her değişiklikten sonra dördü de yeşil olmalı:

```
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
php artisan test
npm run build
```

CI aynı komutları çalıştırır: `.github/workflows/quality-gate.yml`.

## Yapı

| Klasör | İçerik |
|---|---|
| `app/Services` | Tüm iş kuralları ve sorgular (controller'da sorgu yok) |
| `app/Http/Middleware` | `EnsureTenantContext`, `EnsurePermission` — zincir: auth → tenant → permission |
| `routes/panel.php` | Panel route'ları; her route kendi iznini taşır |
| `database/seeders/data/rbac_scope_permission_matrix.csv` | RBAC tek kaynağı (12 rol, 107 izin) |
| `tests/Architecture` | Mimari kuralları kaynak taramasıyla zorlayan testler |
| `public/css/ofisvio.css` | Tasarım sistemi (derlenmez) |

Kurallar: `CLAUDE.md`. Yol haritası: `ROADMAP.md`. Yeni modül kalıbı: `routes/BOOTSTRAP.md`.
