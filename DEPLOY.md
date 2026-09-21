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
| `SESSION_SECURE_COOKIE` | `true` | Doctor üretimde şart koşar (secure + httponly + same_site) |
| `SECURITY_HSTS` | `true` | HTTPS yanıtlarında HSTS; ters proxy zaten ekliyorsa `false` |
| `QUEUE_CONNECTION` | `redis` / `database` + worker | `sync` yalnız geliştirme |
| `MAIL_MAILER` | gerçek sağlayıcı (`smtp`, `ses`, …) | Davet, şifre sıfırlama, 2FA kurtarma |
| `KYC_SCANNER` | `clamav` | Zorunlu; `none` üretimde açılmaz |
| `CLAMAV_ADDRESS` | `tcp://clamav:3310` ya da `unix:///var/run/clamav/clamd.ctl` | Doctor canlı tarama yapar |
| `CLAMAV_TIMEOUT` | `30` | — |
| `OFISVIO_INSTALLATION_ID` | kuruluma özgü kısa ad | Önbellek anahtarı bağlamı (faz 60f); paylaşılan Redis'te kurulumlar birbirinin anahtarını okuyamaz |
| `PERF_SAMPLE_RATE` / `PERF_SLOW_QUERY_MS` | `0.05` / `100` | İstek profili örnekleme oranı ve yavaş sorgu eşiği (Performans › Dashboard) |
| `SEARCH_CONSOLE_ENABLED` + `SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON` | servis hesabı JSON (metin ya da dosya yolu) | Search Console verisi (faz 60d); mülk adresi panelde, servis hesabı mülke eklenmeli |
| `ANALYTICS_ENABLED` + `ANALYTICS_SERVICE_ACCOUNT_JSON` | servis hesabı JSON | GA4 Data API (faz 60d); mülk kimliği panelde, servis hesabı GA4'te görüntüleyici |
| `PAGESPEED_ENABLED` (+ `PAGESPEED_API_KEY`) | `true` | Core Web Vitals ölçümü (haftalık zamanlayıcı) |
| `AI_ENABLED` + `AI_API_KEY` (+ `AI_MODEL`, `AI_PRICE_*`) | Anthropic anahtarı | AI Content Engine (faz 60e); insan onayı zorunlu, AI yayındaki içeriğe dokunmaz |
| `TRUSTED_PROXIES` | proxy IP listesi ya da `*` | Ters proxy/CDN arkasında HTTPS algılama (audit F-12) |
| `BACKUP_PATH` · `BACKUP_ENCRYPTION_KEY` · `BACKUP_KEEP_DAYS` | dış dizin · base64:32 bayt · 30 | Günlük şifreli yedek + doğrulama (§8, audit F-03) |
| `APP_PREVIOUS_KEYS` | eski APP_KEY (yalnız rotasyon sırasında) | `ofisvio:reencrypt` sonrası kaldırılır (§9, audit F-14) |
| `GOOGLE_MAPS_ENABLED` + `GOOGLE_MAPS_API_KEY` | Maps Platform anahtarı | Harita/konum (faz 61b); tarayıcıya gitmez, yalnız sunucu tarafı |

**Entegrasyon secret'ları (faz 61b):** tüm sağlayıcı anahtarları panelden de girilebilir (`/panel/ayarlar/api`, `secrets.manage`); panel değerleri `integration_secrets` tablosunda **APP_KEY ile şifreli** saklanır ve env'in önüne geçer. `APP_KEY` değişirse panelde saklanan secret'lar çözülemez ve yeniden girilmelidir — anahtar rotasyonundan önce env'e taşıyın. Giden webhook'lar (faz 61c) kuyruk ister (`queue:work`); teslimat logu 30 gün budanır.

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

**Tercih edilen yol: CI artefaktı + `deploy/deploy.sh` (§7).** Elle kurulumda tek komut (audit F-17 — web tabanlı
`/install` ucu bilinçli olarak YOKTUR; kurulum sunucu erişimi olan operatörün işidir):

```bash
php artisan ofisvio:install --check          # PHP ≥ 8.3, uzantılar, yazılabilir dizinler, APP_KEY, DB (değiştirmez)
php artisan ofisvio:install                  # migrate → referans veri → storage:link → OFISVIO_ADMIN_* hesapları → kilit (storage/app/.installed) → doctor
php artisan ofisvio:install --upgrade        # kurulu sistemde: migrate + referans veri + doctor
```

Eşdeğer adımlar (ne yaptığını görmek için):

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan storage:link                     # medya kütüphanesi (public/storage -> storage/app/public)
php artisan db:seed --force                  # roller, izin matrisi, hizmet kataloğu, varsayılan site (idempotent); şubeler panelden (Lokasyonlar › Yeni)
php artisan ofisvio:blog-starter --user=admin@ornek.com   # (isteğe bağlı) başlangıç blog seti: 14 yazı + kapak + SEO/GEO; var olan slug atlanır, --draft ile taslak
php artisan optimize                         # config + route + view cache
php artisan ofisvio:doctor                   # 0 dönmüyorsa trafik açma
php artisan ofisvio:smoke                    # çalışan sistem: rotalar, DB, önbellek, kuyruk, zamanlayıcı, depolama, şifreleme, tenant izolasyonu
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

**Bildirim merkezi ilk kurulumu** — varsayılan kural seti + yönetici WhatsApp alıcısı
(numara kodda/seeder'da YOKTUR; env'den okunur, sonrası panelden yönetilir):

```env
OFISVIO_BOOKING_NOTIFY_WHATSAPP=+90XXXXXXXXXX
WHATSAPP_ENABLED=true
WHATSAPP_ACCESS_TOKEN=<Meta Cloud API kalıcı token>
WHATSAPP_PHONE_NUMBER_ID=<gönderen numara id>
```

```bash
php artisan ofisvio:bootstrap-notifications
```

Meta Cloud API, 24 saatlik pencere dışındaki işletme mesajları için onaylı şablon
ister: Ayarlar › WhatsApp › "Onaylı şablon adı" (tek gövde parametreli). Boşsa düz
metin gönderilir (yalnız açık oturumda teslim olur). Sağlayıcı kapalıyken talepler
yine kaydedilir; bildirim günlüğü "atlandı" yazar.

Kayıt kapalıdır; hesap yalnız bu komutla ve panel davetleriyle açılır. Personel
2FA'sız hiçbir panel ekranına giremez — ilk girişte `/panel/hesap/guvenlik`.

## 4. Süreçler

**Cron (zamanlayıcı)** — dakikada bir; `content:publish-scheduled` (kalp atışı) ve
`booking:expire-requests` (onaysız talepler süresi dolunca EXPIRED) buradan çalışır:

```
* * * * * cd /var/www/ofisvio && php artisan schedule:run >> /dev/null 2>&1
```

**Kuyruk işçisi** (supervisor/systemd) — bildirimler (WhatsApp/e-posta/SMS/uygulama içi)
kuyruktan gider; işçi yoksa `queued` kalır, rezervasyon yine kaydedilir:

```
php artisan queue:work --tries=5 --max-time=3600
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

`deploy/deploy.sh --rollback` bir önceki sürüme döner (`previous` → `current`, önbellekler, doctor, `up`).
Migrasyonlar geri alınmaz (`migrate:rollback --step=1` mümkün ama içerik tabloları veri taşır) — veri geri
alınacaksa §8 yedekten geri yükleme. Elle geri almada `php artisan optimize:clear && php artisan optimize`, sonra
`ofisvio:doctor` + `ofisvio:smoke`.

## 7. Kalite kapısı ve sürüm artefaktı (CI/CD, audit F-02/F-16)

`.github/workflows/quality-gate.yml` her push'ta: pint → phpstan → `composer validate --strict` + `composer audit`
→ migration + seed → test (Redis) → `npm audit --audit-level=high` + build; ayrı job'da tenant/RBAC/mimari testleri.
main'de kapı yeşilse **release** job'u üretim paketini üretir: `composer --no-dev`, `npm run build`,
`ofisvio-<commit>-<run>.tar.gz` + `.sha256` + `RELEASE.json` (commit, run, lock özetleri) + **SLSA provenance
attestation** (GitHub). Artefakt 90 gün saklanır.

Sunucuda deploy (fail-closed; her adım düşerse bakım modu AÇIK kalır ve `current` değişmez):

```bash
gh run download <run-id> -n ofisvio-<commit>-<run> -D /tmp/rel
APP_ROOT=/var/www/ofisvio deploy/deploy.sh /tmp/rel/ofisvio-<commit>-<run>.tar.gz
```

1. `sha256sum -c` · 2. `gh attestation verify --repo esdsellercom-cpu/ofisvio` (provenance; `SKIP_ATTEST=1` yalnız
kayıtlı istisna) · 3. `releases/<build>` aç, `shared/.env` + `shared/storage` bağla, `APP_ENV=production` ve
`APP_DEBUG=false` şart · 4. `down` · 5. `migrate --force` + önbellekler · 6. `ofisvio:doctor` · 7. `current` anahtarla,
`queue:restart` · 8. `ofisvio:smoke` → `up`. Smoke düşerse `--rollback`; `ofisvio:health-alert` (15 dk) doctor hatalarını
yöneticilere bildirir.

## 8. Yedekleme ve geri yükleme (audit F-03)

`ofisvio:backup` günlük 02:30 (zamanlayıcı, tek sunucu): veritabanı dökümü (sqlite kopya / `mysqldump` /
`pg_dump`) + `storage/app/private` (KYC, sözleşme) + `storage/app/public` (medya) + `manifest.json` → tek arşiv,
`.sha256`, **BACKUP_ENCRYPTION_KEY** tanımlıysa parça parça AES-256-GCM ile şifreli (`.tar.enc`). Doğrulama gerçek
açmadır (sha256 + şifre çözme + tar + manifest) ve her yedekten sonra otomatik koşar; doctor son doğrulanmış yedek
`BACKUP_MAX_AGE_HOURS` (26) içinde değilse üretimde HATA verir.

```env
BACKUP_PATH=/var/backups/ofisvio           # sunucu DIŞINA senkronlayın (nesne depolama, immutable/WORM, erişimi kısıtlı)
BACKUP_ENCRYPTION_KEY=base64:...           # php -r "echo 'base64:'.base64_encode(random_bytes(32));" — anahtarı yedekten AYRI saklayın
BACKUP_KEEP_DAYS=30                        # retention; en az BACKUP_KEEP_MIN (3) yedek kalır
```

```bash
php artisan ofisvio:backup --list
php artisan ofisvio:backup --verify=ofisvio-YYYYMMDD-HHMMSS-xxxxxx
php artisan ofisvio:restore ofisvio-YYYYMMDD-HHMMSS-xxxxxx            # yalnız doğrular
php artisan ofisvio:restore ofisvio-YYYYMMDD-HHMMSS-xxxxxx --force    # bakım modu → DB + dosyalar → doctor → up (doctor geçmezse bakımda kalır)
```

**Prova zorunludur:** üç ayda bir staging'de BACKUP → yok et → RESTORE → `ofisvio:smoke` (CI'da
`BackupRestoreTest` aynı akışı SQLite ile her koşuda çalıştırır). `mysqldump`/`mysql` (ya da `pg_dump`/`psql`)
sunucuda PATH'te olmalı; parola ortam değişkeniyle geçer, komut satırına/loga yazılmaz.

## 9. Anahtar rotasyonu (audit F-14)

Şifreli alanlar tek listede (`KeyRotationService::FIELDS`: panel secret'ları, webhook secret/gövde, TC kimlik).

```env
APP_PREVIOUS_KEYS=base64:<eski>
APP_KEY=base64:<yeni>
```

```bash
php artisan ofisvio:reencrypt --dry-run     # eski anahtarla şifreli kayıt sayısı
php artisan ofisvio:reencrypt               # yeni anahtarla yeniden yazar (audit: security.key_rotated)
php artisan ofisvio:doctor                  # "Anahtar rotasyonu: bekleyen yok" → APP_PREVIOUS_KEYS kaldırılabilir
```

Bekleyen kayıt varken eski anahtarı silmek veri kaybıdır; doctor üretimde bunu HATA sayar.

## 10. Saat, proxy, saklama

- **NTP (audit F-04):** chrony/systemd-timesyncd zorunlu; doctor `timedatectl`/`chronyc` ile senkron durumunu ve
  DB ↔ uygulama saat farkını (> 5 sn hata) denetler. Webhook tekrar penceresi, JIT süresi, TOTP ve imzalı URL'ler
  saate bağlıdır.
- **Ters proxy (audit F-12):** nginx/CDN arkasında `TRUSTED_PROXIES=<ip,ip>` (tek katman
  için `*`); boşsa X-Forwarded-Proto yok sayılır → HSTS ve secure çerez üretilmez.
- **KVKK saklama (audit F-09):** Ayarlar › Gizlilik & saklama; `ofisvio:retention` günlük 03:00 (anonimleştirme,
  eski KYC dosyaları yalnız ayar açıksa). Yasal metin sürümleri (F-07): Footer'da KVKK sayfası seçili olmalı — doctor
  üretimde KVKK sürümü yoksa hata verir; vitrin rızaları o anki sürüme bağlanır.
- **Çerez rızası (F-06):** GA4/GTM yalnız ziyaretçi "Kabul et" derse yüklenir; bant metni Footer ayarında.
