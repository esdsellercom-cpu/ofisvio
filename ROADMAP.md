# OFISVIO — Birleşik Yol Haritası (Backend + Frontend)

Bu dosya §77'deki geliştirme sırasını **mevcut duruma oturtur**. Kaynak doğruluğu
bu depodur (`C:\dev\ofisvio`); `ofisvio-rbac` paketi 16 Eylül 2026'da buraya
kuruldu ve artık yalnızca arşivdir.

**Durum işaretleri:** ✅ bitti · 🟡 kısmen · ⛔ engelli · ⬜ başlanmadı

---

## Kalite kapısı (§76) — 16 Eylül 2026'dan beri yeşil

| Komut | Durum |
|---|---|
| `./vendor/bin/pint --test` | ✅ |
| `./vendor/bin/phpstan analyse` (level 6) | ✅ 0 hata |
| `php artisan test` | ✅ **157/157** (Unit 10 · Feature 141 · Architecture 6) |
| `npm run build` | ✅ |

Laravel 13.32 / PHP 8.3.33 / Node 24 / Vite 8. CI: `.github/workflows/quality-gate.yml`
— GitHub'da da yeşil (esdsellercom-cpu/ofisvio, 16 Eylül 2026). İlk pipeline
koşusu Redis önbelleğiyle 7 testte 429 verdi: paylaşılan throttle anahtarı +
test izolasyonu (ikisi de kodda düzeltildi, `RateLimitTest`).

İlk koşuda kök nedeninden düzeltilenler (0702880): sabit `location_id` FK ihlali,
`tests/Architecture` hiçbir suite'te değildi, Laravel iskelet `ExampleTest`'i,
15 ilişkide eksik generic tip, `TenantScope` `@implements`, `app.css`'in public
asset'i `@import` etmesi, `install.ps1` BOM'u, `scopeBindings` için yanlış
parametre adı (`{document}` → `Company::documents()` yoktu).

---

## FAZLAR — §77 sırası, mevcut duruma göre

### 1. RBAC CSV → Migration + Seeder ✅
252 satır · 12 rol · 107 izin · 52 JIT · 2 dual-control.

### 2. Tenant Context Engine ✅
Üyelik yolu + personel yolu (`ContextSwitchService::switchTo` ikisini de kabul
eder, `context_switch_logs.entry_path` ile ayırır), model-level TenantScope
(fail-closed), `runAsSystem()` kaçış kapısı. 409/403 tarayıcıda seçim ekranına
yönlenir; 404 her yerde 404 (enumeration savunması).

### 3. Security Acceptance Test Skeleton ✅
157 test; "izin verilmemeli" senaryoları her modülde var.

### 4. CI/CD Pipeline ✅
GitHub Actions: pint · phpstan · test (Redis) · build + ayrı P0 güvenlik job'ı.

### 5. P0 Security Infrastructure 🟡
Var: tenant izolasyonu, RBAC+scope, JIT, dual-control, fail-closed, audit
tabloları, login throttling (5/dk e-posta+IP), session fixation savunması,
**2FA (TOTP, kurtarma kodları, replay koruması) — personel için zorunlu**
(`staff.2fa` middleware'i: doğrulanmış 2FA olmadan hesap sayfası dışında
hiçbir panel ekranı açılmaz), hassas POST'larda throttle (KYC yükleme, JIT,
davet, context), **File Quarantine**: `MalwareScanner` sözleşmesi,
`ClamAvScanner` (INSTREAM, sahte clamd'ye karşı test edildi), enfekte belge
`QUARANTINED` + `quarantine/` ön eki + hiçbir yoldan (JIT dahil) açılmaz,
tarayıcı yoksa yükleme reddedilir (fail-closed), `KYC_SCANNER=none`
production'da açılışta durur.
Yok: passkey, Integration Gateway, Secret Management, SSRF koruması,
Webhook Security (entegrasyon modülleriyle birlikte gelecek).

### 6. Auth ✅
Fortify: login / logout / şifre sıfırlama. **Kayıt kapalı** — hesaplar davetle
(`OrganizationOnboardingService`, `MembershipService`); şifre sıfırlama e-postası
davet olarak da kullanılır (Türkçe, markalı). İlk personel:
`php artisan ofisvio:make-admin <email>`.

### 7. Customer / Company ✅
Organizasyon açılışı (personel, `user.manage`), şirket oluşturma
(`organization.manage`), şirket detayı + aktivasyon adımları + durum geçmişi,
üyelik yönetimi (`membership.manage`: davet, rol, askıya alma, geri alma).

### 8. Manual KYC ✅ (backend + frontend)
Yükleme (tip başına, eskisi SUPERSEDED), durum, indirme (müşteri JIT'siz;
personel yalnızca açık grant ile), JIT talebi (gerekçe ≥ 10 karakter, TTL
üst sınırı), onay / red / ek bilgi (her biri kendi izniyle), organizasyon içi
kuyruk, organizasyon seçim ekranında bekleyen belge sayısı (`KycQueueService`,
gerekçeli tenant-scope baypası).

### 9. CMS Core ✅
`websites` (§66: `organization_id` NULL = Ofisvio vitrini, `is_default`),
`contents` (page | post, markdown gövde, SEO alanları), `content_revisions`
(her kayıtta anlık görüntü). State machine `ContentStatus`: DRAFT → IN_REVIEW
→ (APPROVED →) PUBLISHED | SCHEDULED → ARCHIVED; `requires_approval`
(yasal/vergi/KYC — "P0B") onaysız yayınlanamaz; yalnızca taslak düzenlenir
(canlı metin önce yayından kalkar). Her geçiş kendi `content.*` izniyle ayrı
route. `content:publish-scheduled` dakikada bir. Vitrin: `/blog`, `/blog/{slug}`,
`/{slug}`; footer yasal bağlantıları yayındaki sayfalardan. Seeder yalnızca
TASLAK iskelet açar (uydurma metin yayınlanmaz; yazı yoksa bölüm gizlenir).
Karar notları: içerik operatör sitesi için, personel global izinle; müşteri
siteleri (owner:company `content.edit`) faz 10 ile gelir.

### 10. Website / Page Engine 🟡
Vitrin statik Blade + CMS sayfaları. Eksik: çoklu website (tenant başına site,
`websites.organization_id` dolu kayıtlar), tema/şablon seçimi, menü yönetimi.

---

### ⛔ 11–28 (Performance, Cache, SEO/GEO, Content, AI, Command Center'lar)
Tamamı 9. ve 10. fazlara bağlı; sıra ve önkoşullar değişmedi.

---

## FRONTEND FAZLARI

| # | Frontend fazı | Durum |
|---|---|---|
| F0 | Tasarım sistemi (token + bileşen) | ✅ `public/css/ofisvio.css` — auth + panel katmanı eklendi |
| F1 | Vitrin (ana sayfa) | ✅ |
| F2 | Giriş + organizasyon seçimi | ✅ |
| F3 | Müşteri paneli iskeleti | ✅ genel bakış, şirketler, üyeler |
| F4 | KYC belge yükleme + durum takibi | ✅ |
| F5 | Admin KYC inceleme kuyruğu + JIT talep ekranı | ✅ |
| F6 | Şirket aktivasyon takip ekranı | ✅ şirket detayında (adımlar + geçmiş) |
| F7 | CMS editörü | ✅ liste/süzgeç, form (markdown), akış eylemleri, revizyonlar |
| F8 | SEO/GEO Command Center | ⛔ |
| F9 | Performance + Cache Command Center | ⛔ |

---

## SIRADAKİ ADIMLAR

1. **Üretim ortamı** — `KYC_SCANNER=clamav` + clamd konteyneri; `MAIL_MAILER`
   gerçek sağlayıcı; `APP_ENV=production` (NullScanner açılışta reddedilir).
2. **Faz 10** — Website Engine: çoklu website + tenant siteleri (`content.*`
   müşteri rolleri), menü/tema. Sonra 11+ (SEO/GEO/Performance) açılır.
3. **İçerik** — editör panelden yazıları ve yasal sayfaları yazıp yayınlar;
   `config/ofisvio.php`'deki kalan vitrin metinleri (çözümler, planlar) faz 10'da
   bloklara taşınır.

Kapıyı yeniden koşturmak için proje kökünde dört komut (yukarıda).
