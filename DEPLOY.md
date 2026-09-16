# Ofisvio — üretime alma

Tek sayfa, sırayla. Her adımın doğrulaması `php artisan ofisvio:doctor`'dır:
üretimde (`APP_ENV=production`) tek hata bile çıkış kodu **1** döner; deploy
betiği bunu kapı olarak kullanır.

## 1. Ortam (.env)

| Anahtar | Üretim değeri | Neden |
|---|---|---|
| `APP_ENV` | `production` | NullScanner açılışta reddedilir; doctor uyarıları hataya döner |
| `APP_DEBUG` | `false` | Hata sayfası config/yol sızdırmasın |
| `APP_URL` | `https://ofisvio.com` | Canonical, sitemap, og:url, güvenli çerez |
| `APP_KEY` | `php artisan key:generate` | — |
| `APP_TIMEZONE` | `Europe/Istanbul` | Zamanlama ekranları bu saate göre |
| `DB_CONNECTION` | `mysql` / `pgsql` | SQLite yalnız geliştirme |
| `CACHE_STORE` | `redis` (ya da `database`) | ContentCache sürüm sayacı, named throttle, doctor kalp atışı |
| `SESSION_DRIVER` | `redis` / `database` | — |
| `QUEUE_CONNECTION` | `redis` / `database` + worker | `sync` yalnız geliştirme |
| `MAIL_MAILER` | gerçek sağlayıcı (`smtp`, `ses`, …) | Davet, şifre sıfırlama, 2FA kurtarma |
| `KYC_SCANNER` | `clamav` | Zorunlu; `none` üretimde açılmaz |
| `CLAMAV_ADDRESS` | `tcp://clamav:3310` ya da `unix:///var/run/clamav/clamd.ctl` | Doctor canlı tarama yapar |
| `CLAMAV_TIMEOUT` | `30` | — |

KYC belgeleri `storage/app/private` altındadır (`private` diski; URL yok,
public değil). Kalıcı ve yedeklenen bir birim olmalı.

## 2. clamd

Docker Compose örneği:

```yaml
services:
  clamav:
    image: clamav/clamav:stable
    volumes:
      - clamav-db:/var/lib/clamav
    healthcheck:
      test: ["CMD", "clamdcheck.sh"]
      interval: 60s
volumes:
  clamav-db:
```

İlk imza indirmesi dakikalar sürer; `ofisvio:doctor` "clamd erişilemez"
dediği sürece uygulama KYC yüklemesini reddeder (fail-closed — tasarım gereği).

## 3. Kurulum / güncelleme

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan storage:link                     # medya kütüphanesi (public/storage -> storage/app/public)
php artisan db:seed --force                  # roller, izin matrisi, lokasyonlar, varsayılan site (idempotent)
php artisan optimize                         # config + route + view cache
php artisan ofisvio:doctor                   # 0 dönmüyorsa trafik açma
```

İlk kurulumda personel hesabı — şifre `.env`'den, kodda/seeder'da yoktur:

```env
OFISVIO_ADMIN_EMAIL=admin@ofisvio.com
OFISVIO_ADMIN_PASSWORD=<≥16 karakter, büyük/küçük harf, rakam, özel karakter>
```

```bash
php artisan ofisvio:bootstrap-accounts
```

`OFISVIO_TEST_CUSTOMER_*` yalnız test/staging içindir; production'da komut reddeder.
Alternatif (interaktif): `php artisan ofisvio:make-admin <e-posta> --name="Ad Soyad"`.

Kayıt kapalıdır; hesap yalnız bu komutla ve panel davetleriyle açılır. Personel
2FA'sız hiçbir panel ekranına giremez — ilk girişte `/panel/hesap/guvenlik`.

## 4. Süreçler

**Cron (zamanlayıcı)** — dakikada bir; `content:publish-scheduled` buradan
çalışır ve doctor'un okuduğu kalp atışını bırakır:

```
* * * * * cd /var/www/ofisvio && php artisan schedule:run >> /dev/null 2>&1
```

**Kuyruk işçisi** (supervisor/systemd):

```
php artisan queue:work --tries=3 --max-time=3600
```

**Doctor'ı izleme**: `php artisan ofisvio:doctor --json` çıktısındaki `ok`
alanı; her deploy sonrası ve saatlik bir kontrolde.

## 5. Web sunucusu

- Kök: `public/`. `storage/` ve `.env` dışarıdan erişilemez olmalı.
- HTTPS zorunlu; `APP_URL` https ise Laravel güvenli çerez üretir.
- Çoklu site: müşteri alan adları (`websites.domain`) aynı uygulamaya
  yönlenir; `CurrentWebsite` Host'a göre siteyi seçer, bilinmeyen host
  varsayılan (Ofisvio) siteye düşer. Sertifika her alan adı için gerekir.
- `public.cache` middleware'i misafir sayfalarında `public, max-age` + ETag
  basar; önünde CDN/reverse proxy varsa bu başlıklara saygı duymalıdır.

## 6. Geri alma

Migrasyonlar geri alınabilir (`migrate:rollback --step=1`), ancak içerik
tabloları veri taşır — geri almadan önce DB yedeği. Kod geri alındığında
`php artisan optimize:clear && php artisan optimize`, sonra yine
`ofisvio:doctor`.

## 7. Kalite kapısı CI'da

`.github/workflows/quality-gate.yml` her push'ta pint + phpstan + test + build
koşar (Redis önbelleğiyle). Yeşil olmayan commit deploy edilmez.
