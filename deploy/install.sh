#!/usr/bin/env bash
# Ofisvio ilk kurulum betiği — sürüm paketi sunucuya açıldıktan sonra TEK komutla kurar.
#
#   ./install.sh              # ön kontrol → .env → APP_KEY → izinler → ofisvio:install → optimize → doctor
#   ./install.sh --check      # hiçbir şeyi değiştirmeden yalnız ön kontrol
#   ./install.sh --upgrade    # kurulu sistem üstüne yeni sürüm (bakım modu → migration → optimize → doctor → up)
#
# Paketin AÇILDIĞI dizinden çalıştırılır (artisan ile aynı klasör). Fail-closed: herhangi bir adım düşerse
# betik durur, çıkış 1'dir ve --upgrade'de uygulama bakım modunda bırakılır. Hiçbir adımda "|| true" yoktur.
set -euo pipefail

PHP="${PHP:-php}"
MODE="${1:-}"

log()  { printf '\n\033[1m[%s] %s\033[0m\n' "$(date +%H:%M:%S)" "$*"; }
die()  { printf '\n\033[31mHATA: %s\033[0m\n' "$*" >&2; exit 1; }
note() { printf '  %s\n' "$*"; }

# Betik paket kökünde (install.sh) ya da depoda (deploy/install.sh) olabilir; artisan'ın bulunduğu dizine geçilir.
HERE="$(cd "$(dirname "$0")" && pwd)"
if [[ -f "$HERE/artisan" ]]; then cd "$HERE"; elif [[ -f "$HERE/../artisan" ]]; then cd "$HERE/.."; else die "artisan bulunamadı: betiği paketin açıldığı dizinden çalıştırın"; fi
[[ -f composer.json ]] || die "paket kökü değil: composer.json yok"
[[ -d vendor ]] || die "vendor/ yok: yanlış paket (üretim paketi vendor içerir) ya da eksik açılmış"
[[ -d public/build ]] || die "public/build yok: derlenmiş varlıklar eksik"

log "1/7 Ortam"
command -v "$PHP" >/dev/null || die "php bulunamadı (PHP=/usr/bin/php8.3 ile verebilirsiniz)"
note "$("$PHP" -r 'echo "PHP ".PHP_VERSION;')"
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP 8.3+ gerekiyor"
MISSING="$("$PHP" -r 'echo implode(" ", array_values(array_filter(["mbstring","openssl","pdo","json","fileinfo","gd","intl","zlib","ctype","tokenizer","xml"], fn ($e) => ! extension_loaded($e))));')"
[[ -z "$MISSING" ]] || die "eksik PHP uzantıları: $MISSING"

log "2/7 Yapılandırma (.env)"
if [[ ! -f .env ]]; then
  [[ -f .env.example ]] || die ".env yok ve .env.example da yok"
  cp .env.example .env
  cat <<'MSG'

.env dosyası .env.example'dan oluşturuldu. Kuruluma devam etmeden ÖNCE doldurun:

  APP_ENV=production   APP_DEBUG=false   APP_URL=https://alanadiniz.com
  DB_CONNECTION / DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD
  CACHE_STORE=redis    SESSION_DRIVER=database    QUEUE_CONNECTION=redis
  KYC_SCANNER=clamav   CLAMAV_ADDRESS=tcp://127.0.0.1:3310
  OFISVIO_ADMIN_EMAIL / OFISVIO_ADMIN_PASSWORD        (ilk süper yönetici)
  BACKUP_PATH / BACKUP_ENCRYPTION_KEY                 (yedek şifreleme anahtarı yedekten AYRI saklanır)
  TRUSTED_PROXIES                                     (ters proxy/CDN arkasındaysa)

Doldurduktan sonra bu betiği yeniden çalıştırın.
MSG
  exit 1
fi

envval() { sed -n "s/^${1}=//p" .env | head -1 | tr -d '"'"'"'\r'; }
[[ -n "$(envval DB_DATABASE)" ]] || die ".env içinde DB_DATABASE boş"
[[ -n "$(envval APP_URL)" ]] || die ".env içinde APP_URL boş"
if [[ "$(envval APP_ENV)" == "production" ]]; then
  [[ "$(envval APP_DEBUG)" == "false" ]] || die "üretimde APP_DEBUG=false olmalı"
  case "$(envval APP_URL)" in https://*) ;; *) die "üretimde APP_URL https olmalı" ;; esac
fi
if [[ -z "$(envval APP_KEY)" ]]; then
  note "APP_KEY üretiliyor"
  "$PHP" artisan key:generate --force
else
  note "APP_KEY mevcut (korunuyor — değiştirilirse şifreli alanlar okunamaz)"
fi

log "3/7 Dizinler"
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
[[ -w storage && -w bootstrap/cache ]] || die "storage/ ve bootstrap/cache yazılabilir olmalı (chown -R www-data:www-data)"

if [[ "$MODE" == "--check" ]]; then
  log "4/7 Ön kontrol (ofisvio:install --check)"
  "$PHP" artisan ofisvio:install --check
  log "Ön kontrol tamam — kurulum için parametresiz çalıştırın."
  exit 0
fi

if [[ "$MODE" == "--upgrade" ]]; then
  log "4/7 Bakım modu"
  "$PHP" artisan down --retry=60   # bir adım düşerse bakım modu KALIR (fail-closed); düzeltip yeniden çalıştırın
  log "5/7 Migration + referans veri (ofisvio:install --upgrade)"
  "$PHP" artisan ofisvio:install --upgrade
else
  log "4/7 Ön kontrol"
  "$PHP" artisan ofisvio:install --check
  log "5/7 Kurulum (migration, referans veri, storage bağlantısı, yönetici hesabı)"
  "$PHP" artisan ofisvio:install
fi

log "6/7 Önbellek (config, route, view)"
"$PHP" artisan optimize

log "7/7 Sistem denetimi (ofisvio:doctor)"
"$PHP" artisan ofisvio:doctor || die "doctor hata verdi; yukarıdaki satırları düzeltmeden trafiği açmayın"

if [[ "$MODE" == "--upgrade" ]]; then
  "$PHP" artisan queue:restart
  "$PHP" artisan up
  log "Güncelleme tamam."
  exit 0
fi

cat <<'MSG'

Kurulum tamam. Sırasıyla:

  1) cron (dakikada bir) — zamanlanmış yayın, hatırlatma, yedek (02:30), KVKK imha (03:00), sistem alarmı:
       * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1

  2) kuyruk işçisi (supervisor/systemd):
       php artisan queue:work --tries=5 --max-time=3600

  3) nginx: root <paket>/public;  HTTPS zorunlu;  proxy_set_header X-Forwarded-Proto https;

  4) ilk yedek ve çalışan sistem denetimi:
       php artisan ofisvio:backup
       php artisan ofisvio:smoke

  5) panel: APP_URL/panel → OFISVIO_ADMIN_EMAIL ile giriş → 2FA kurulumu → Ayarlar › Header/Footer'dan
     KVKK / gizlilik / çerez sayfalarını yayınlayın (doctor bunu ister), sonra lokasyon ve hizmetleri girin.
MSG
