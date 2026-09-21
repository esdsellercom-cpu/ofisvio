#!/usr/bin/env bash
# Ofisvio üretim deploy betiği (audit F-02 / §33–34). Kaynak: CI "release" job'unun ürettiği artefakt.
#
#   deploy/deploy.sh /path/ofisvio-<sha>-<run>.tar.gz          # doğrula → bakım → migrate → doctor → smoke → up
#   deploy/deploy.sh --rollback                                 # önceki sürüme dön (up) — migration geri alınmaz
#
# Dizin düzeni (APP_ROOT altında):
#   releases/<build>/      açılan sürümler        shared/.env  shared/storage  (sürümler arasında ortak)
#   current -> releases/<build>                    previous -> bir önceki
#
# Fail-closed: sha256, provenance (gh attestation), migration, doctor ya da smoke düşerse uygulama BAKIM MODUNDA kalır,
# current değiştirilmez, çıkış 1. Hiçbir adımda "|| true" yoktur.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/ofisvio}"
PHP="${PHP:-php}"
REPO="${GITHUB_REPO:-esdsellercom-cpu/ofisvio}"
SKIP_ATTEST="${SKIP_ATTEST:-0}"        # yalnız gh CLI olmayan acil durumda 1 (kayda geçirin)
KEEP_RELEASES="${KEEP_RELEASES:-5}"

log() { printf '\n\033[1m[%s] %s\033[0m\n' "$(date +%H:%M:%S)" "$*"; }
die() { printf '\n\033[31mHATA: %s\033[0m\n' "$*" >&2; exit 1; }

artisan_current() { (cd "$APP_ROOT/current" && "$PHP" artisan "$@"); }

if [[ "${1:-}" == "--rollback" ]]; then
  [[ -L "$APP_ROOT/previous" ]] || die "previous sürüm yok"
  PREV="$(readlink -f "$APP_ROOT/previous")"
  log "Geri alınıyor → $PREV"
  ln -sfn "$PREV" "$APP_ROOT/current"
  artisan_current config:cache && artisan_current route:cache && artisan_current view:cache
  artisan_current queue:restart
  artisan_current ofisvio:doctor || die "doctor geçmedi; bakım modu açık bırakıldı"
  artisan_current up
  log "Geri alma tamam (DİKKAT: veritabanı migration'ları geri alınmadı; gerekiyorsa yedekten geri yükleyin)."
  exit 0
fi

ARCHIVE="${1:-}"
[[ -f "$ARCHIVE" ]] || die "kullanım: deploy.sh <ofisvio-*.tar.gz> | --rollback"
SHA_FILE="${ARCHIVE}.sha256"
[[ -f "$SHA_FILE" ]] || die "sha256 dosyası yok: $SHA_FILE"

log "1/8 Artefakt bütünlüğü (sha256)"
(cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$SHA_FILE")") || die "sha256 uyuşmuyor"

log "2/8 Kaynak doğrulama (provenance)"
if [[ "$SKIP_ATTEST" == "1" ]]; then
  echo "UYARI: provenance doğrulaması atlandı (SKIP_ATTEST=1) — kayda geçirin."
else
  command -v gh >/dev/null || die "gh CLI yok; provenance doğrulanamıyor (SKIP_ATTEST=1 yalnız bilinçli istisna)"
  gh attestation verify "$ARCHIVE" --repo "$REPO" || die "provenance doğrulanamadı: artefakt CI'dan gelmemiş ya da değiştirilmiş"
fi

BUILD="$(basename "$ARCHIVE" .tar.gz)"
TARGET="$APP_ROOT/releases/$BUILD"
[[ -d "$TARGET" ]] && die "sürüm zaten açılmış: $TARGET"

log "3/8 Açılıyor → $TARGET"
mkdir -p "$TARGET" "$APP_ROOT/shared/storage"
tar -xzf "$ARCHIVE" -C "$TARGET"
[[ -f "$TARGET/RELEASE.json" ]] || die "RELEASE.json yok — bu bir CI artefaktı değil"
[[ -f "$APP_ROOT/shared/.env" ]] || die "shared/.env yok (ilk kurulum: DEPLOY.md §1)"
ln -sfn "$APP_ROOT/shared/.env" "$TARGET/.env"
rm -rf "$TARGET/storage" && ln -sfn "$APP_ROOT/shared/storage" "$TARGET/storage"
grep -q '^APP_DEBUG=false' "$APP_ROOT/shared/.env" || die "APP_DEBUG=false değil — DEPLOY BLOCK"
grep -q '^APP_ENV=production' "$APP_ROOT/shared/.env" || die "APP_ENV=production değil — DEPLOY BLOCK"

log "4/8 Bakım modu"
if [[ -L "$APP_ROOT/current" ]]; then artisan_current down --retry=30; fi   # ilk kurulumda current yok

log "5/8 Migration + önbellekler"
(cd "$TARGET" && "$PHP" artisan migrate --force && "$PHP" artisan config:cache && "$PHP" artisan route:cache && "$PHP" artisan view:cache && "$PHP" artisan storage:link --force >/dev/null) \
  || die "migration/önbellek başarısız; eski sürüm bakımda bekliyor — deploy.sh --rollback ya da yedekten geri yükleme"

log "6/8 Doctor (ortam kapısı)"
(cd "$TARGET" && "$PHP" artisan ofisvio:doctor) || die "doctor geçmedi; bakım modu AÇIK, current değişmedi"

log "7/8 Sürüm anahtarlama + kuyruk"
if [[ -L "$APP_ROOT/current" ]]; then ln -sfn "$(readlink -f "$APP_ROOT/current")" "$APP_ROOT/previous"; fi
ln -sfn "$TARGET" "$APP_ROOT/current"
artisan_current queue:restart

log "8/8 Smoke (çalışan sistem) → up"
if artisan_current ofisvio:smoke; then
  artisan_current up
  log "DEPLOY TAMAM: $BUILD ($(jq -r .commit "$TARGET/RELEASE.json" 2>/dev/null || echo '?'))"
else
  die "smoke geçmedi; bakım modu AÇIK bırakıldı — deploy.sh --rollback ile geri dönün, alarm bildirimi ofisvio:health-alert ile gider"
fi

# Eski sürümleri buda (current/previous korunur)
CURRENT_REAL="$(readlink -f "$APP_ROOT/current")"
PREVIOUS_REAL=""; [[ -L "$APP_ROOT/previous" ]] && PREVIOUS_REAL="$(readlink -f "$APP_ROOT/previous")"
for old in $(ls -1dt "$APP_ROOT"/releases/*/ | tail -n +"$((KEEP_RELEASES + 1))"); do
  old="${old%/}"
  [[ "$old" == "$CURRENT_REAL" || "$old" == "$PREVIOUS_REAL" ]] && continue
  rm -rf "$old"
done
