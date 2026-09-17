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
| `php artisan test` | ✅ **294/294** (Unit 12 · Feature 266 · Architecture 16) |
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
257 satır · 12 rol · 109 izin · 52 JIT · 2 dual-control (`website.view`,
`website.manage` faz 10'da eklendi).

### 2. Tenant Context Engine ✅
Üyelik yolu + personel yolu (`ContextSwitchService::switchTo` ikisini de kabul
eder, `context_switch_logs.entry_path` ile ayırır), model-level TenantScope
(fail-closed), `runAsSystem()` kaçış kapısı. 409/403 tarayıcıda seçim ekranına
yönlenir; 404 her yerde 404 (enumeration savunması).

### 3. Security Acceptance Test Skeleton ✅
181 test; "izin verilmemeli" senaryoları her modülde var.

### 4. CI/CD Pipeline ✅
GitHub Actions: pint · phpstan · test (Redis) · build + ayrı P0 güvenlik job'ı.

### 5. P0 Security Infrastructure ✅ (passkey hariç)
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
**Integration Gateway** (`App\Integrations\Gateway`): dış sağlayıcıya giden TEK yol
(`Http::` yalnız burada — MockDataDetectionTest zorlar); sağlayıcı env ile
açılır, kapalıysa/secret eksikse RED; zaman aşımı; yönlendirme yok;
`integration_logs` (secret/gövde/sorgu dizgisi yok). **Secret Management**
(`SecretStore`): env → config('integrations'), DB/kod/log/yanıt dışı, panelde
maskeli, doctor eksikte hata. **SSRF koruması** (`UrlGuard`): https + 443,
kullanıcı bilgisi yok, IP literali yok, DNS çözümü özel/loopback/link-local/
metadata/IPv4-mapped reddi, yol base_url dışına çıkamaz. **Webhook Security**
(`/webhooks/{provider}`, `WebhookReceiver`): HMAC-SHA256(gövde.zaman) sabit zamanlı
karşılaştırma, zaman damgası toleransı (replay), (provider, event_id) tekil
(idempotent tekrar teslim), kapalı sağlayıcı/secret yok → 404, throttle.
Sağlayıcı adaptörleri (iyzico, SC, analytics, AI, SMS, e-Fatura) kimlik bilgisi
gelince geçit üzerinden yazılır. Yok: passkey (WebAuthn — ayrı paket + JS kararı).

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

### 10. Website / Page Engine ✅
Çoklu website: `CurrentWebsite` isteğin Host'unu `websites.domain` ile eşler
(port/büyük harf yok sayılır; bilinmeyen host varsayılana düşer, asla site
üretmez). Müşteri sitesi kendi iskeletiyle (`layouts.tenant`: site adı +
yayındaki sayfa menüsü) kendi sayfa/yazılarını gösterir; içerik siteler arası
sızmaz. Personel `/panel/websiteler` ile site açar (ad, alan adı, organizasyon —
§66), editörde site seçer.
**Müşteri sitesi yönetimi (v2 ✅):** `/panel/sirketler/{company}/site` — şirket
kapsamlı `content.edit / review / schedule` (matris: owner üçünü, company_admin
yalnız edit taşır). Müşteri sayfasını düzenler, incelemeye gönderir ve
**zamanlar**; yayın = zamanlama (`content.publish` personelde kalır; onay
gerektiren metin Ofisvio onayı bekler). Yayındaki sayfa için çalışma taslağı
açar, taslak da zamanlanır; `content:publish-scheduled` zamanı gelince
birleştirir. Tenant sınırı: `{content}` model binding DEĞİL, servis
organizasyonun sitelerine süzer, yabancı içerik 404; yabancı şirket 404.
Yeni sayfa açma ve doğrudan yayın personelde (`/panel/icerik`, site seçici).
**Müşteri SEO alanı** `/panel/sirketler/{company}/site/seo`: `seo.view`
ayarlar + içerik denetimi + sitemap; `seo.edit` başlık son eki / varsayılan
açıklama / dil; `seo.publish` (yalnız owner) indeksleme anahtarı — kapatınca
robots.txt Disallow, her sayfa noindex, sitemap boş. Yabancı site 404.
**Menü yönetimi:** `contents.show_in_nav` + `nav_order`; menü = yayındaki,
gösterilen sayfalar sırayla (`ContentService::navigation`, önbellekli);
"Yazılar" bağlantısı yalnız yayında yazı varsa. Müşteri
`/site/menu/{website}` (`content.edit`, company), personel `/panel/icerik/menu`
— aynı ekran, aynı servis; yabancı sayfa id'si atlanır. Menü yapısal alandır:
akış/revizyon dışı, `updateNavigation` dışında yazılmaz.
**Tema:** `websites.theme` → `html[data-theme]`; üç token seti ofisvio.css'te
(kum/gece/deniz), derleme yok; personel site formundan, müşteri menü
ekranından (`content.edit`). **Sayfa dışı bağlantılar:** `websites.nav_links`
("Etiket | URL", https/mailto/tel/yol; javascript: reddedilir; ≤ 8).
**Vitrin blokları:** `site_blocks` (çözümler, toplantı odaları, dahil olanlar,
üyelik sütun/satırları, footer sütunları, fiyat notu) — kayıt yoksa
`config/ofisvio.php` varsayılanı; `/panel/icerik/bloklar` satır tabanlı
düzenleme, doğrudan canlı (`content.publish`), boş = varsayılana dönüş.
Faz kapandı; kalan yalnız tasarım varlıkları (kapak görselleri).

---

### 11. Performance Foundation ✅
Ölçüm birimi SORGU SAYISI (CI'da deterministik; süre gürültülü).
`tests/Feature/Performance/QueryBudgetTest`: liste sayfaları satır sayısıyla
büyümez (2 vs 8 kayıt aynı sayı) + sayfa başına üst sınır. İlk ölçüm dashboard
8 şirkette **126** sorgu, KYC sayfası **62** — N+1. Düzeltme: istek başına
yetki memo'su (`AuthorizationService`: izinler tek sorgu, kullanıcının tüm
grant'leri tek sorgu, şirket→organizasyon memo), `TenantContext` memo'su
(personel/üyelik/organizasyon), `PerRequestCaches` middleware'i (istek dışında
kapalı), `PanelLayoutComposer` parçalarda çalışmaz, `KycService::statusSummaries`
tek sorgu. Sonuç: dashboard **11**, KYC **14**, vitrin 5, içerik 6.
**Baseline artefaktı:** `php artisan ofisvio:perf-baseline` dokuz temsilî
sayfayı (vitrin, blog, lokasyonlar, sitemap, beş panel ekranı) geçici 2FA'lı
personelle transaction içinde ölçer (sorgu, medyan ms, tepe MB), JSON yazar;
CI her koşuda artefakt olarak saklar (`perf-baseline-<sha>`, 30 gün). Kapı
değil, karşılaştırma kaynağı. Ölçüm database önbellek sürücüsünde her
`remember()`'ın 5 sorgu ettiğini gösterdi → `ContentCache` istek başına sürüm
memo'su (singleton + `PerRequestCaches`), `has()+get()` yerine tek `get()`,
sayaçta önce `increment()`: vitrin 29 → 18 sorgu. HTTP önbellek başlıkları
faz 12'de.

### 12–14. Cache Engine · Tenant İzolasyonu · Gözlem ✅ (Redis etiket/CDN hariç)
`ContentCache`: website başına SÜRÜMLÜ anahtar (`site:{id}:v{n}:{ad}`) —
geçersizleme sürümü artırır, başka sitenin anahtarına dokunmaz; etiket
gerektirmez (database/Redis). CMS okumaları (yazı listesi, sayfalar, slug)
önbellekte; yayın akışı ve zamanlanmış yayın kendiliğinden geçersiz kılar.
Vitrin HTTP başlıkları: misafire `public, max-age=60, s-maxage=300` + ETag/304;
oturum açmışa `private, no-store`. Panel `/panel/onbellek`: sürüm, isabet/
ıskalama/oran, ısıtma (`cache.warm`), geçersizleme JIT'li (`cache.invalidate`,
kaynak = website id; global purge kaynak 0, yıkıcı). `EnsurePermission` boş
kapsam ve sabit kaynak id (`=0`) destekler.
**cache.inspect:** `/panel/onbellek/{website}/anahtarlar` — `remember()`
adları site başına kayıt kümesine yazar (sürücü listeleme yapmaz), ekran
var/yok, tür, öğe sayısı, boyut ve ilk üç başlığı gösterir; içerik BASILMAZ
(matris: hassas olabilir). **cache.settings (JIT, kaynak `cache_settings`):**
site başına uygulama TTL'i + misafir `max-age`/`s-maxage`; boş = kod
varsayılanı; kaydedince sürüm atlar. `PublicCacheHeaders` süreleri geçerli
siteden okur. Eksik (dış bağımlılık): Redis etiketli genişletme, CDN purge.

### 15 · 21. SEO Engine · Sitemap ✅ (Search Console hariç)
`SeoService`: website başına ayar (`seo_title_suffix`, `seo_default_description`,
`robots_index`, `seo_locale`), içerik başına `noindex`. Her vitrin sayfası
`<head>`: title (çekirdek + son ek), description, canonical (site alan adı),
robots, Open Graph, JSON-LD (WebSite / Article / WebPage). `/robots.txt` ve
`/sitemap.xml` geçerli siteye göre (noindex ve taslak dışarıda; `robots_index`
kapalıysa Disallow: / + boş sitemap). Panel `/panel/seo`: ayarlar JIT'li
(`seo.settings`, kaynak website), `seo.audit` deterministik içerik denetimi
(başlık/açıklama uzunluğu, H1, kısa gövde, noindex). F8 v1.
**Breadcrumb şeması:** her içerik sayfası `@graph` = WebPage/Article +
BreadcrumbList (Ana sayfa → Yazılar → Kategori → başlık). `seo.edit` alanları
müşteri panelinde (faz 10). hreflang: site tek dilli (`seo_locale`), ikinci
dil gelmeden anlamlı değil — bilinçli olarak yok. Eksik (dış servis): Search
Console / analytics (faz 20).

### 16–17. GEO Engine · Entity / Knowledge Graph ✅
Varlık modeli: `Organization` (website: ad, `legal_name`, `sameAs`, `@id`) ←
`LocalBusiness` (lokasyon: adres, koordinat, telefon, `openingHours`,
sunulan çözümler `makesOffer`) + `BreadcrumbList`. Lokasyon sayfaları
`/lokasyonlar`, `/lokasyon/{slug}` (yalnızca Ofisvio vitrini; müşteri
sitesinde 404), sitemap'te. Şemaya YALNIZCA var olan veri girer (koordinat
yoksa `geo` düğümü yok — uydurma değer yok); eksikler `geo.audit` bulgusudur.
Panel `/panel/geo`: lokasyon varlık alanları (`geo.edit`), Organization
kimliği JIT'li (`geo.settings`, kaynak website). Ana sayfa `WebSite` şeması
Organization düğümünü `sameAs`/`legalName` ile taşır.
**FAQPage:** içerik gövdesindeki `## Soru?` başlıkları + cevap paragrafları
(≥ 2 çift) otomatik FAQ şeması — içerikte olmayan SSS şemaya girmez.
**Service:** Ofisvio ana sayfasında çözümler bloğundan Service düğümleri
(fiyat metni sayıya çevrilmez, Offer description). **geo.publish:** GEO
panelinde şube vitrine al / kaldır (`is_published`; `is_active` ayrı);
kaldırılan şube sayfa, liste ve sitemap'ten düşer. **Harita:** lokasyon
sayfasında OpenStreetMap gömme, yalnız "Haritayı göster" tıklanınca yüklenir
(üçüncü taraf isteği ziyaretçi seçimi; JS kapalıyken bağlantı). hreflang: tek
dil (bkz. faz 15).

### 18 · 23. Content Engine · Internal Linking ✅
**Çalışma taslağı** (`content_drafts`, içerikle bire bir): yayındaki içerik
canlıda kalırken kopyası düzenlenir, aynı akıştan geçer (DRAFT → IN_REVIEW →
[APPROVED] → birleştirme), yayınlanınca `publishDraft()` alanları içeriğe
yazar, revizyon düşer, önbelleği geçersiz kılar, taslağı siler. Rotalar
`/panel/icerik/{content}/taslak/*`, izinler ana akışla aynı (`content.edit /
review / approve / publish`); onay gerektiren içeriğin taslağı onaysız
birleşmez. Yayından kaldırma hâlâ mümkün ama artık zorunlu değil.
**Kategori sayfaları** `/blog/kategori/{slug}` (kategori serbest metin; slug
eşlemesi; yalnız yayındaki yazılardan türer; sitemap'te). **İlgili yazılar**
yazı sayfasında (önce aynı kategori, sonra en yeni; önbellekli listeden,
ek sorgu yok). Düzeltme: iskelet composer'ı liste sayfalarının SEO başlığını
eziyordu (`/blog` canonical ana sayfa çıkıyordu).
Birleştirme anında slug tekilliği yeniden çözülür (taslak beklerken slug
alınmışsa `-2`). **Etiketler:** `contents.tags` / `content_drafts.tags`
(virgülle girilir, küçük harf, tekil, ≤ 10), `/blog/etiket/{slug}` sayfası,
yazı altında etiket bağlantıları, sitemap'te. **İç bağlantı önerisi:** içerik
sayfasında (personel + müşteri paneli) aynı sitedeki yayındaki içerikler,
puan = ortak etiket ×3 + aynı kategori ×2 + başlık kelimesi ×1, kopyalanacak
markdown bağlantısıyla.

### 24. İçerik Takvimi ✅
`/panel/icerik/takvim?ay=YYYY-MM`: aylık ızgara (pazartesi başlangıç;
zamanlanmış → `scheduled_for`, yayında → `published_at`), yan panelde iş
hattı (taslak / incelemede / onaylı / çalışma taslakları) ve **gecikmiş
zamanlama** uyarısı (zamanı geçmiş SCHEDULED = `content:publish-scheduled`
çalışmıyor sinyali). Zamanlanmış çalışma taslakları (↻) birleşme gününde. Sorgu bütçesi: kayıt sayısından bağımsız (12 sorgu üst
sınır); yazı sayfası da bütçede (ilgili yazılar önbellekli listeden).
**Haftalık görünüm:** `?hafta=YYYY-MM-DD` (önceki/sonraki hafta, aylığa dön);
**editör iş yükü:** yazar başına taslak/inceleme/onaylı/zamanlanmış/çalışma
taslağı sayıları. Sürükle-bırak yeniden zamanlama bilinçli olarak yok: JS'siz
çalışma ilkesi; zamanlama içerik sayfasındaki formdan.

### 29. Panel tamamlama ✅ (kullanıcı geri bildirimi, 16 Eylül 2026)
Panelde görünen boşluklar — fazlarda karşılığı olmayan, ama ürünün
kullanılabilmesi için şart olan ekranlar:
**Kullanıcı yönetimi** `/panel/kullanicilar` (`user.manage`): liste (roller,
2FA), personel daveti (şifre belirleme bağlantısı; şifre girilmez/görülmez),
global rol atama / askıya alma / etkinleştirme; kendi rolünü ve son aktif
super_admin'i askıya alma reddedilir; müşteri rolleri yalnız görünür.
**Site genel ayarları:** `websites.contact_phone/contact_email/tagline/address`,
`Website::brand()` (site > config [yalnız Ofisvio] > boş); personel site
formunda, müşteri menü ekranında; üst şerit / footer / tenant footer.
**Ana sayfa yönetimi:** hero, üst şerit ve bölüm başlık/açıklamaları
`config('ofisvio.texts')` + `texts` bloğu (anahtar başına alan); liste blokları
ile aynı "Ana sayfa" ekranı. **Sayfa yönetimi:** menüde Sayfalar / Yazılar /
Takvim / Ana sayfa ayrımı; **alt sayfa** (`parent_id`, tek seviye,
`/ebeveyn/sayfa` kanonik yol, `parent_slug` denormalize; alt sayfa menüde
değil, ebeveyn sayfasında listelenir; breadcrumb ve sitemap yolu kullanır).

### Denetim (16 Eylül 2026) — bkz. `AUDIT.md`
Frontend↔backend, mock/localStorage/sabit veri, auth/RBAC/tenant, CRUD, önbellek,
güvenlik denetimi. Düzeltmeler: ticari içerik config → `site_blocks`/`websites`
(`SiteBlockSeeder`); Talepler (CRM v1) ekranı; env tabanlı hesap açılışı
(`ofisvio:bootstrap-accounts`); içerik silme + liste arama/sayfalama; Türkçe
hata sayfaları; gruplu panel menüsü; koruyucu testler (`MockDataDetectionTest`,
`ApiRouteConsistencyTest`, `TenantIsolationTest`, `ErrorHandlingTest`).

### 30. Panel boşlukları (denetim sonrası) ✅
**Lokasyon:** yeni şube (`geo.edit`, gizli açılır, tekil slug), künye (ad, şehir,
bölge, adres, rozet, etiket, fiyat metni, sıra, operasyon; slug sabit), silme
(`geo.publish`, yalnız vitrinde olmayan; talepler nullOnDelete). **Şirket künyesi:**
unvan + vergi no (`company.update`, company kapsamı; tenant dışı 404).
**Organizasyon adı:** genel bakışta (`organization.manage`; slug sabit).
**Website silme:** `website.manage`, yalnız varsayılan olmayan ve içeriksiz site;
soft delete, alan adı/slug boşa çıkar. **Medya kütüphanesi** `/panel/icerik/medya`:
JPEG/PNG/WebP ≤ 5 MB; zincir MIME (içerikten) → uzantı → sihirli bayt →
boyut → ClamAV (tarayıcı yoksa RED, enfekte yazılmaz) → sha256 (site içi
yinelenen engeli) → public disk UUID. Kapak (`contents.cover_media_id` +
`cover_url` denormalize, önbellekli listeler sorgusuz) ve hero
(`websites.hero_media_id`, `website.manage`); vitrin kart/yazı/hero + `og:image`;
kullanımdaki görsel silinemez; yabancı site 404. `php artisan storage:link` gerekir.

### 31. Sistem ekranları ✅
**Denetim kaydı** `/panel/denetim` (`audit.view`, salt okunur): JIT erişimleri
(gerekçe/süre/iptal), personel organizasyon girişleri (IP), şirket durum
geçişleri (kim/neden); arama + tarih süzgeci. `AuditLogService` allowlist’te
(global okuma, yazma yok). **Performans** `/panel/performans` (`performance.view`):
son baseline artefaktı, site önbellek istatistikleri, zamanlayıcı kalp atışı;
`performance.audit` ile üretim dışı yeniden ölçüm ve doctor kontrol listesi.

### 32. Parite: vitrin ↔ panel ✅ (17 Eylül 2026)
Vitrindeki her yönetilebilir metin panelden gelir: menü etiketleri, üst şerit /
menü / hero / çözüm kartı CTA'ları, teklif formu başlık-açıklama-vaatleri, ön talep
aracı, yazılar başlığı, WhatsApp ön yazılı mesajı (`SiteBlockService::TEXT_KEYS`;
`OPTIONAL_TEXT_KEYS` boş bırakılınca gizlenir). **WhatsApp numarası** (E.164) ve
**çalışma saatleri** site genel ayarıdır (`websites.whatsapp_number/business_hours`);
yüzen WhatsApp düğmesi yalnız numara varsa basılır. Footer sütun maddeleri
`Etiket = /yol|#bolum|https://` biçimiyle hedef taşır; hedefsiz madde düz metin
(ölü `#` yok). KVKK bağlantısı yayındaki aydınlatma sayfasına.

### 33. Booking v1 ✅ (17 Eylül 2026)
`rooms` (lokasyona bağlı Ofisvio varlığı; tür, kapasite, saatlik ücret, açık saat,
slot, üst sınır; **geo.edit** ile `/panel/geo/lokasyon/{slug}/odalar`) ve `bookings`
(şirkete ait, `company_id` tenant sınırı; `location_id` masa için denormalize).
**Uygunluk motoru** `BookingService`: açık saat, geçmiş, 60 gün ufuk, slot katı,
`max_hours`, pasif oda/lokasyon, askıdaki şirket; **çakışma hiçbir koşulda
atlanmaz** (işlem + `lockForUpdate`). Müşteri `/panel/sirketler/{company}/rezervasyonlar`
(`booking.view/create` owner·company_admin·employee, `booking.cancel` owner·company_admin,
başlangıca ≥2 saat). Personel: `/panel/rezervasyonlar` (`booking.view` global —
operations_admin) ve **lokasyon masası** `/panel/rezervasyonlar/lokasyon/{slug}`
(`booking.view,location`: resepsiyon kendi şubesi, `user_roles.location_id`);
masadan açma `booking.create,location`; **`booking.admin_override` JIT** (kaynak
`booking_location`/lokasyon) açık saat/ufuk/pasif oda kuralını atlar, çakışmayı
atlamaz, masadan iptal yalnız bununla. Vitrin ön talep saat çipleri odalardan
türetilir (`publicSlots`, önbellekli; oda yoksa saat sorulmaz — `booking_slots`
config sabiti kalktı). Altyapı düzeltmeleri: `permission:…,location` artık
organizasyon bağlamı istemez (Ofisvio şubesi tenant değildir); lokasyon kapsamlı
internal rol (resepsiyon) 2FA zorunluluğuna girer (`TenantContext::hasInternalRole`);
kullanıcı yönetimi lokasyon kapsamlı rolü şubeyle atar (`UserAdminService::locationScopedRoles`).
Kalan: ödeme/fatura bağlantısı (faz 19+), e-posta bildirimi, tekrarlayan rezervasyon.

### 34. Booking Engine v2 · Bildirim Merkezi · Ayar Merkezi · Genel Denetim ✅ (17 Eylül 2026, master prompt)
**Booking v2 (§4–11, §57):** `BookingStatus` durum makinesi (REQUESTED → PENDING_APPROVAL/CONFIRMED →
CHECKED_IN → COMPLETED; REJECTED/CANCELLED/EXPIRED/NO_SHOW), `booking_status_history`, referans no
(`OV-2026-000124`, önek ayardan), `uuid` ile müşteri durum sayfası. **Onay politikası ayardan**
(`booking.auto_confirm`, min/max önceden, tampon, iptal süresi, talep süresi; lokasyon üzerine
yazabilir); vitrin rozeti ("aynı gün teyit") ayardan türer. Uygunluk motoru bekleyen tutmaları da
meşgul sayar, tampon uygular; çakışma işlem + kilit ile her koşulda reddedilir. **Vitrin akışı**
`/rezervasyon`: lokasyon → gerçek odalar → canlı slotlar → form (KVKK, E.164 telefon, bot tuzağı)
→ talep; ana sayfa toplantı bölümü gerçek odalardan (oda yoksa basılmaz). Personel:
`/panel/rezervasyonlar` sekmeli (onay bekleyen/bugün/yaklaşan/…) + gerçek dashboard toplamları
(doluluk, iptal oranı, gelmedi, tutar, ort. süre), detay sayfası (geçmiş, bildirimler, denetim izi)
ve eylemler: onay/red (`booking.approve`), check-in/tamamlandı/gelmedi/iç not/yeniden planlama
(`booking.manage`), iptal (JIT). `booking:expire-requests` zamanlayıcısı.

**Bildirim merkezi (§12–19):** `notification_recipients` (kanal+adres DB'de, grup, lokasyon;
telefon kodda yok — `ofisvio:bootstrap-notifications` env'den ilk alıcıyı kurar), `notification_rules`
(olay × kanal × grup), `notification_templates` (panelden; teknik varsayılan kodda), `notification_logs`
(deneme, sağlayıcı mesaj id, hata; secret yok). Akış: domain olayı (`BookingStatusChanged`, commit
sonrası) → `NotificationService::dispatch` → kuyruk (`SendNotification`, üstel geri çekilme, tükenince
süper yöneticiye uyarı) → kanal adaptörü → `WhatsAppProviderInterface` (`MetaWhatsAppAdapter`, Gateway
üzerinden; şablon/düz metin) · `SmsProviderInterface` · e-posta · uygulama içi (zil + gelen kutusu).
Sahte adaptör yok; testler HTTP sahtelemeyle gerçek adaptörü koşturur. Panel `/panel/bildirimler`
(kurallar, alıcılar maskeli, şablonlar, günlük).

**Ayar merkezi (§31–33):** `SettingsRegistry` (tip/varsayılan/kural/kapsam/açıklama kodda),
`settings` tablosu, kalıtım lokasyon › şirket › organizasyon › kurulum › varsayılan; `/panel/ayarlar`
(settings.view/manage; `?lokasyon=` üzerine yazma). **Genel denetim (§46):** `audit_logs`
(actor/action/entity/before/after/ip/UA; secret maskeli) — booking, oda, ayar, bildirim değişiklikleri;
`/panel/denetim?tur=general`. Matris: `booking.approve/manage`, `settings.view/manage`,
`notification.view/manage`. Kalan: ödeme/fatura (faz 19+), takvim görünümü (gün/hafta), CMS page builder (faz 35).

### 35. Sayfa kurucu (Website Experience Manager v1) ✅ (17 Eylül 2026, master prompt §21–36)
Ana sayfa = sıralı **bölümler** (`site_sections` taslak, `site_revisions` yayınlanmış anlık
görüntü). Panel `/panel/icerik/tasarim`: bölüm ekle (kütüphane: hero, istatistik, çözümler,
nasıl çalışır, lokasyonlar, toplantı, dahil olanlar, üyelikler, yazılar, teklif formu, serbest
metin, SSS, CTA şeridi), sürükle-bırak/ok ile sırala, çoğalt, gizle, sil, çapa, cihaz görünürlüğü
(mobil/masaüstü), **zamanlama** (başlangıç/bitiş), bölüm başına başlık/açıklama üzerine yazma;
**CTA eylem tipleri** (§29: çapa, sayfa, rezervasyon akışı, teklif formu, lokasyonlar, yazılar,
telefon/WhatsApp/e-posta site ayarından, https). Dinamik bölümler gerçek varlıklardan (lokasyon,
oda, yazı, blok). Taslak (content.edit) ≠ yayın (content.publish → revizyon + site önbelleği);
**revizyon listesi + geri alma**; **imzalı süreli önizleme** (`/onizleme/{website}`, noindex,
masaüstü/tablet/mobil iframe). Üst menü yayınlanmış bölümlerin çapalarından (gizli bölüme ölü
bağlantı yok). Hepsi denetim izli. Kalan: çok dillilik, alt sayfalar için kurucu (şimdilik
Markdown sayfalar), A/B deneyi. Ek: duyuru şeridi (global bileşen), rezervasyon takvimi
(hafta/gün × oda), lead/KYC olayları Bildirim Merkezi'nde, `MockDataDetectionTest` sabit telefon/
WhatsApp/fiyat taraması. Kapsama matrisi: `COVERAGE.md`.

### 36. Lokasyon medya yönetimi ✅ (17 Eylül 2026, faz 3 prompt)
`location_media` (kategori: kapak, galeri, iç/dış mekân, toplantı odası, ofis, coworking, resepsiyon,
ortak alan, olanak; sıra; birincil), `locations.cover_media_id`, `media.title/caption/variants/status`.
**Karantina zinciri** (`MediaService::upload`): private diske al → MIME (içerikten) → uzantı (MIME'dan)
→ sihirli bayt (getimagesize + MIME eşleşmesi) → boyut → ClamAV (erişilemezse RED, enfekte asla
public'e çıkmaz) → sha256 → onay → public UUID + GD ile 480/960/1600 responsive kopya → karantina
temizlenir; her red denetim izinde aşama adıyla. Admin `/panel/geo/lokasyon/{slug}/gorseller` (geo.edit):
yükle, kapak yap, birincil, sürükle-bırak/ok ile sırala, alt/başlık/altyazı, dosya değiştir (bağ+meta
korunur, eski dosya kullanılmıyorsa silinir), kaldır. Vitrin: lokasyon sayfası kapak (eager, LCP) +
kategori galerisi, kartlarda kapak; `site.partials.picture` (`srcset`/`sizes`/`loading=lazy`/
`decoding=async`); og:image + LocalBusiness image kapaktan. Kodda görsel yolu yok; görsel yoksa boş
durum kutusu. Her değişiklikte site önbellekleri düşer. `LocationMediaTest` (3 test, 7 adımlı senaryo).

### 37. Hizmet modülü ✅ (17 Eylül 2026, faz 4 prompt)
`services` (ad, slug, özet, Markdown açıklama, fiyat metni, rezervasyon türü → odalar, amiral, aktif,
sıra, kapak) + `location_service` (ilişkisel). Taşıma: `site_blocks.solutions` ve `locations.tags`
→ hizmetler/ilişki, ardından kaldırıldı (veri kaybı yok). Admin `/panel/hizmetler` (service.view/manage);
lokasyon künyesi yalnız var olan hizmetlerden **seçer** (yeni hizmet buradan açılmaz). Vitrin: çözüm
kartları, hero süzgeci, teklif formu seçenekleri, lokasyon kart/sayfa etiketleri, JSON-LD Service +
makesOffer (URL'li), sitemap ve hizmet sayfaları (`/cozumler`, `/cozum/{slug}`: sunan lokasyonlar +
rezervasyona bağlı odalar) hizmet tablosundan; kodda hizmet adı dizisi yok. Önbellek: hizmet/ilişki
değişince site sürümleri düşer. `ServiceTest` (ekle→vitrin, bağla→etiket, kaldır→düşer, pasif, önbellek,
tenant izolasyonu). Metin anahtarı `solutions_lede` eklendi (sabit cümle kalktı).

### 38. Panel kabuğu — "Kolektif Panel" kalıbı ✅ (17 Eylül 2026, dashboard prompt)
Kabuk `layouts/panel.blade.php` + `public/css/panel.css` (ofisvio.css'ten sonra; tasarım
token'larını panel paletine eşler → var olan bileşenler yeniden yazılmadan geçer) + `public/js/panel.js`
(dar ekranda kenar menüsü, tema düğmesi; depolama yok). 252px kenar menüsü: gruplu (Genel bakış ·
Operasyon · Müşteri · Dijital · Sistem · Hesap), sırayla numaralı, rozetli; `App\View\Menu\PanelMenu`
yalnız var olan modülleri izne göre üretir (ölü öge yok), 2FA kurulmamış personel yalnız Kurulum grubunu
görür. Rozetler (`PanelBadgeService`): bekleyen onay, yeni talep, başarısız bildirim, bekleyen KYC,
okunmamış — izne göre istenir, **tek sorguda** sayılır (sorgu bütçesi değişmedi). Üst çubuk: arama
(`/panel/ara`, `PanelSearchService`: rezervasyon/talep/şirket/kullanıcı, yalnız izinli kümeler),
organizasyon bağlamı, tema (koyu/açık/sistem — `users.ui_theme`, `POST /panel/hesap/tema`), zil, kullanıcı.
Dashboard: personel için KPI şeridi + kartlar (bugünkü rezervasyonlar, onay bekleyenler, yeni talepler,
lokasyon performansı) gerçek servis toplamlarından, blok yalnız izinle; müşteri için şirket sayaçları.
`PanelShellTest` (menü/rozet/KPI, 2FA kapısı, arama izinleri, tema). Ayrıca panelde `ofisvio.js`
artık yükleniyor (sürükle-bırak sıralama panelde çalışmıyordu).

### 39. Artifact menü paritesi — eksik modüller ✅ (17 Eylül 2026)
Artifact'ın 19 başlığının tamamı gerçek modül olarak panelde; menü sırası Genel bakış · Operasyon ·
Üyelik · Finans · Büyüme · Dijital · Sistem · Hesap. Hiçbir öge ölü değil; ticari veri yalnız DB, seed yok.
- **39a** Alanlar (`/panel/alanlar`, tüm odalar), Üye dizini (`/panel/uyeler`), Raporlar & analitik
  (`ReportService`, sekmeler: gelir/tahsilat/doluluk/üyelik/talepler/bildirim/içerik), Entegrasyonlar & API
  (sağlayıcı geçidi maskeli + kanal sağlığı + webhook uçları), Yerelleştirme (ayarlar › genel grup).
- **39b** Üyelikler & paketler: `plans` + `subscriptions` (tenant, fiyat anlık görüntü), `SubscriptionService`
  (aç/yenile/iptal/expireStale, MRR), RBAC `subscription.manage`, müşteri görünümü, `subscriptions:expire`.
- **39c** Finans: `invoices` + `payments`, `InvoiceService` (taslak → yayın [numara ayardan] → gecikmiş →
  ödendi; kısmi tahsilat; iptal JIT'li `invoice.cancel`), `/panel/faturalar`, `/panel/tahsilat`, müşteri
  faturaları, Ayarlar › Finans, `invoices:mark-overdue`.
- **39d** Etkinlikler & topluluk: `events` + `event_registrations`, panel CRUD/yayın/katılımcı durumu,
  vitrin `/etkinlikler`, `/etkinlik/{slug}` + KVKK'lı kayıt (kontenjan, tekrar e-posta), sitemap, önbellek.
- **39e** Franchise yönetimi: `franchise_applications`, vitrin `/franchise` formu, panel değerlendirme
  (durum/sorumlu/not, audit). RBAC `event.*`, `franchise.*`.
- Menü rozetleri (bitişi yaklaşan üyelik, gecikmiş fatura, yeni franchise) tek sorguda; dashboard KPI'ları
  (günlük/aylık ciro, bekleyen/gecikmiş tahsilat, üyelik bitişi, yeni üyelik, yaklaşan etkinlik, franchise)
  ve kartları (gecikmiş ödemeler, yaklaşan üyelik bitişleri) gerçek servis toplamları.
- Testler: `SubscriptionTest`, `InvoiceTest`, `EventFranchiseTest`, `PanelShellTest` (+faz 39 sayfaları).

### 40. Audit P0 (17 Eylül 2026) — bkz. `AUDIT-2026-09-17.md` ✅
1. **Güvenlik başlıkları + çerez sertleştirme:** `SecurityHeaders` middleware (CSP, X-Frame-Options,
   nosniff, Referrer-Policy, Permissions-Policy, HTTPS'te HSTS); doctor üretimde secure/httponly/same_site
   çerezi şart koşar. `SecurityHeadersTest`.
2. **Masa & ofis envanteri:** `spaces` (desk_fixed/desk_flex/office, kat/bölge/kapasite/aylık ücret) +
   `space_assignments` (şirket, üye, üyelik, dönem; tenant company_id). `SpaceService`: CRUD (geo.edit),
   tahsis/sonlandırma (space.manage; satır kilidi, kapasite, bloklu şirket, yabancı üye reddi), doluluk,
   `spaces:end-expired`. Ekranlar: `/panel/alanlar` (masa/ofis + oda sekmeleri, lokasyon doluluğu),
   `/panel/alanlar/{space}` (tahsis), `/panel/geo/lokasyon/{location}/alanlar`, müşteri
   `/panel/sirketler/{company}/alanlar`; paket → alan türü (`plans.space_kind`); dashboard/rapor doluluk. `SpaceTest`.
3. **Para birimi:** tüm tutarlar kuruş (`Money`, `money()`), `MoneyTest`.
4. **Fatura numarası:** `invoice_sequences` atomik sayaç.

### 41. Audit P1 — üyelik yenileme, tahsilat otomasyonu, bildirim kataloğu ✅ (17 Eylül 2026)
- **Yenileme (P1-5):** `subscriptions:renew` — auto_renew üyelik bitişte yeni döneme geçer (güncel paket fiyatı,
  `renewal_count`), `finance.auto_invoice_on_renewal` ile sistem faturası (`InvoiceService::createSystem`,
  aktörsüz, ayardaki KDV/vade) yayınlanır; panelden açılışta "ilk dönem faturasını yayınla" seçeneği.
- **Bildirim kataloğu (P1-8):** `invoice.issued/due_soon/overdue/paid`, `company.suspended`,
  `subscription.expiring/renewed/expired`, `event.registered`, `franchise.applied`; müşteri muhatabı
  `MembershipService::primaryContact` (sahip → şirket yöneticisi). `seedDefaultRules` artık olay bazlı:
  yeni olay var olan kuruluma varsayılan kurallarıyla gelir, düzenlenmiş olaylara dokunmaz.
- **Tahsilat otomasyonu (P1-7):** `invoices:remind-due` (vadeye N gün kala tek seferlik, `finance.reminder_days_before`),
  gecikmede `invoice.overdue`, ödemede `invoice.paid`, `finance:suspend-overdue` (N günden fazla gecikmiş
  açık faturası olan AKTİF şirket `CompanyActivationService` ile askıya alınır — `finance.suspend_after_overdue_days`,
  varsayılan 0 = kapalı), `subscriptions:remind-expiring` (`subscription.expiring_notice_days`). `AutomationTest` (3 test).

### 42. Audit P1 — kimlik sertleştirme ve izin denetimi ✅ (17 Eylül 2026)
- **Şifre politikası (S-3):** `Password::defaults` min 12 + harf + rakam; üretimde `uncompromised()` (HIBP).
- **E-posta doğrulama (S-4):** Fortify `emailVerification`, `User implements MustVerifyEmail`, panel `verified`
  kapısı, `auth.verify-email` görünümü; davetli şifre belirleyince (`ResetUserPassword`) adres doğrulanmış sayılır;
  e-posta değişince yeniden doğrulama; var olan hesaplar migration ile doğrulanmış; bootstrap/make-admin doğrulanmış açar.
- **Müşteri 2FA (S-5):** `security.require_customer_2fa` ayarı (Ayarlar › Güvenlik) — sahip/şirket yöneticisi için
  zorunluluk; `TenantContext::requiresTwoFactor` middleware ve menüyü aynı karara bağlar.
- **İzin denetimi (S-7):** `database/seeders/data/rbac_planned_permissions.txt` + ArchitectureTest: matristeki her izin
  ya kodda kullanılır ya da gerekçeyle planlı listede; kullanılmaya başlanan izin listeden çıkarılmalı (iki yönlü).
- **Operasyon paneli (H-5/M-3):** `/panel/operasyon` (`OperationsDashboardController`, tenant bağlamsız, bloklar izne
  göre) + `panel/partials/ops-overview`; organizasyon dashboard'u (`/panel`) yalnız şirket/KYC; giriş hedefi
  `/panel/baslangic` (`LandingController`: personel → operasyon, müşteri → dashboard); menüde "Operasyon paneli".
- **Rezervasyon → fatura (H-6):** `InvoiceBookingOnStatusChange` dinleyicisi (otomatik keşif): şirket hesabıyla
  onaylanan rezervasyon için `InvoiceService::createForBooking` (booking_id, tek fatura), iptal/red/süre dolumunda
  ödemesiz açık fatura sistemce iptal; `finance.auto_invoice_bookings` ayarı; vitrin şirketsiz talepler fatura üretmez.

### 43. Çekirdek kimlik & yetki sertleştirme ✅ (17 Eylül 2026)
Kapsam: Login/Logout/Register/e-posta doğrulama/şifre sıfırlama/2FA (mevcut, `AuthTest` + `AccountSecurityTest`),
oturum yönetimi, hesap durumu, giriş geçmişi, lokasyon bazlı erişim, güvenlik denetimi (`AuthCoreTest`).
- **Giriş geçmişi:** `login_events` (Prunable 180 gün, `model:prune` günlük) — `RecordLoginEvent` dinleyicisi
  Login/Failed/Logout/Lockout/PasswordReset/OtherDeviceLogout/2FA olaylarını ip + user agent ile yazar. Hesap
  sayfasında "Son girişler", kullanıcı detayında "Giriş geçmişi". Perf baseline ölçümü `setUser/forgetUser`
  kullanır (sahte giriş kaydı yok).
- **Aktif oturumlar (S-9):** `AccountSecurityService::activeSessions` (`sessions` tablosu; yalnız
  `SESSION_DRIVER=database`), "Diğer cihazlardan çıkış" (`password.confirm`, remember token döner, audit
  `user.other_devices_logout`); yönetici `user.manage` ile hedefin tüm oturumlarını kapatır (`user.sessions_terminated`).
- **Hesap durumu:** `users.status` (active/suspended, `suspended_at/_reason`); `AccountSecurityService::suspend/reactivate`
  (kendini askıya alamaz, oturumlar silinir, audit `user.suspended`/`user.reactivated`). Askıdaki hesap
  `Fortify::authenticateUsing`'de genel hatayla reddedilir (durum sızmaz); açık oturum `account.active` middleware'iyle
  düşürülür (zincir: auth → account.active → verified → staff.2fa → tenant → permission).
- **Lokasyon bazlı erişim:** `AuthorizationService::locationIdsWith(user, izin)` (null = global grant; dizi = izinli
  lokasyonlar) + `canAnywhere`; `permission:<izin>,anylocation` kapsamı lokasyonsuz liste ekranına global YA DA lokasyon
  kapsamlı grant'le girer; `SpaceController` listeyi/doluluğu süzer, yabancı lokasyonun alanı 404, tahsis yalnız
  `space.manage` taşınan lokasyonda. Lokasyon yöneticisi/resepsiyon menüde "Masalar, ofisler & odalar" görür.
- **Güvenlik denetimi (kod okuması):** CSRF (webhook HMAC'li istisna dışında tam), XSS (Blade kaçışı; markdown
  `html_input=strip`; JSON-LD `JSON_HEX_TAG|JSON_HEX_AMP` eklendi), SQL (Eloquent/binding; statik raw ifadeler),
  IDOR (tenant scope + `scopeBindings` + `findForCompany`, sınır ihlali 404), kütle atama (`#[Fillable]`,
  `status`/`email_verified_at` yalnız `forceFill` — test), oturum (regenerate, secure/same-site, DB sürücüsü),
  brute-force (login 5/dk e-posta|ip, 2FA 5/dk, lockout kaydı), yetki atlatma (`Gate::before` yasak, route-middleware
  ArchitectureTest, JIT). Açık kalanlar: CAPTCHA (S-8), IP allowlist (S-12), KVKK saklama (S-10).

### 44. SEO & GEO gelişmiş ayarlar ✅ (18 Eylül 2026)
Yalın sistem (websites.seo_* sütunları, `/panel/seo`) değişmedi; gelişmiş ayarlar site başına
`websites.seo_settings` JSON'da, tanımlar `App\Seo\SeoSettingsRegistry` (sekme, tip, varsayılan, kural,
açıklama), okuma/yazma `SeoSettingsService` (sekme bazlı doğrulama, audit `seo.settings_updated`, site
önbelleği sürüm atlar). Panel `/panel/seo/{website}/gelismis/{sekme}` (`SeoSettingsController`, 13 sekme);
yazma rotası sekme moduna göre: **edit** `seo.edit` · **critical** `seo.settings`+JIT · **integration**
`seo.integrations`+JIT (planlı listeden çıktı) · **entity** `geo.settings`+JIT (`geo_entity`). JIT isteği
`/panel/seo/{website}/jit/{settings|integrations|entity}`. Form alanları registry tipinden türer
(`panel/seo/partials/field`: bool/int/string/url/date/text/select/multi/lines/rows/json).
- **Tarama & indeksleme:** sitemap aç/kapat (404), türler, hariç yollar (`/on-ek/*`), özel sitemap/robots,
  robots ek satırları, `<meta name="robots">` yönergeleri (nofollow, noarchive, nosnippet, max-snippet,
  max-image/video-preview), parametreli URL noindex, liste sayfaları noindex, **AI tarayıcıları** (bot listesi,
  erişim aç/kapat → `Disallow: /`, kapalı yollar).
- **Canonical & URL:** otomatik canonical, canonical alan adı, temizlenen parametreler, `SiteSeoPolicy`
  (GLOBAL middleware — rota eşleşmeden önce; Host→Website çözümlemesi de burada, `ResolveWebsite` kaldırıldı):
  panelden yönlendirmeler (301/302, ön ek), http→https ve www (yalnız alan adı tanımlı sitede; https
  varsayılan kapalı), sondaki eğik çizgi, küçük harf; panel/kimlik yolları atlanır. Yanıt tarafı: X-Robots-Tag,
  özel HTTP başlıkları (güvenlik başlıkları ezilemez).
- **Meta / Schema.org:** başlık ve açıklama şablonu, keywords, OG ve Twitter/X varsayılanları; JSON-LD tür
  bazında aç/kapat (Organization, WebSite, WebPage, Article, BreadcrumbList, FAQPage, Service, LocalBusiness,
  Event — veri kaynağı olmayan tür üretilmez), site geneli ve yola bağlı özel JSON-LD.
- **GEO / AI arama:** `/llms.txt` (otomatik: marka tanımı, özetler, hizmetler, lokasyonlar, öncelikli sayfalar,
  kaynaklar, yazılar, SSS, kapalı yollar; ya da özel metin), Organization `description/knowsAbout/areaServed/
  audience`, GEO SSS → ana sayfa FAQPage. **Entity / Knowledge Graph:** alternateName, logo, foundingDate,
  @type, founder, Wikidata/Wikipedia/Knowledge Panel → sameAs, çalışma bölgeleri. **Yerel SEO:** LocalBusiness
  türü, harita/GBP bağlantısı, servis verilen şehir/ilçeler. **Dil & ülke:** ülke kodu, hreflang alternatifleri +
  x-default.
- **İç bağlantı:** `InternalLinkService` — anahtar kelime → adres (metin düğümlerinde, kelime sınırı; <a>/başlık/
  kod hariç; sayfa/hedef sınırı), görünür breadcrumb, ilgili yazılar anahtarı, tembel görsel. **Teknik:**
  `SeoService::technicalReport` (çift/eksik başlık-açıklama, kırık iç bağlantı, yetim sayfa, yönlendirme
  zinciri/döngü, alt metinsiz görsel, karışık içerik, canonical tutarlılığı) + `/site-haritasi` HTML site haritası.
- **Doğrulama & bildirim:** Google/Bing/Yandex doğrulama meta'ları, ek meta satırları, GA4/GTM (CSP Google
  kökenleriyle genişler), **IndexNow** (`ContentPublicationChanged` olayı → `NotifyIndexNowOnContentChange` →
  `NotifyIndexNow` kuyruk işi → Gateway `indexnow` sağlayıcısı, `INDEXNOW_ENABLED`; `/{anahtar}.txt`). Google
  Indexing API servis hesabı ister — dışarıda.
- **Güvenlik:** SecurityHeaders özeti (salt okunur) + X-Robots-Tag. **Geliştirici:** head/body kodu, özel meta,
  özel başlık, preload/dns-prefetch/preconnect. Testler `SeoAdvancedTest` (6).

### 45. Coworking operasyon sertleştirme — çift rezervasyon, kapasite, bakım, fiyat/KDV/indirim ✅ (18 Eylül 2026)
Kapsam denetimi (lokasyon · masa/ofis · toplantı odası · rezervasyon) ve kapatılan açıklar:
- **Çift rezervasyon savunması (3 katman):** (1) `BookingService::lockRoom` — rezervasyon/onay/yeniden planlama
  işlemi oda satırını `lockForUpdate` ile kilitler (aynı odaya eşzamanlı istekler sıraya girer; yalnız aday
  rezervasyon satırlarını kilitlemek phantom read'e açıktı), (2) çakışma sorgusu (tampon dahil, tüm şirketler),
  (3) `booking_slots` tablosu — aralık 15 dk dilimlere bölünür, `(room_id, slot_at)` TEKİL; ihlal →
  `DomainException`, işlem geri alınır. Dilimler odayı meşgul eden durumlarda var, serbest kalınca silinir
  (`apply`), yeniden planlamada taşınır. Slot süresi 15'in katı (15/30/45/60/90/120).
- **Kapasite:** katılımcı ≤ oda kapasitesi (JIT override aşar); yeniden planlamada da. **Rezervasyon sınırları:**
  `booking.max_active_per_company`, `booking.max_per_day` (0 = sınırsız; vitrinde e-posta başına).
- **Bakım durumu:** `maintenance_until` + `maintenance_note` (oda, masa/ofis, lokasyon; `HasMaintenanceStatus`:
  aktif/bakımda/pasif). Bakımdaki oda/lokasyon rezervasyona kapalı (uygunluk tablosu dolu, vitrin listesi dışı,
  `book/reschedule` reddeder); bakımdaki alana tahsis yok; tarih geçince kendiliğinden biter.
- **Olanaklar + kapak görseli:** `amenities` (virgülle, ≤ 20), `cover_media_id` yalnız lokasyon galerisinden
  (`assertCoverInGallery`); oda kartında ve alan detayında gösterilir.
- **Fiyat:** rezervasyon anında `tax_rate` (finance.default_tax_rate) + `tax_amount`; `discount_amount/reason`
  (`applyDiscount`, booking.manage, `/indirim`; tahsilatı başlamışa uygulanmaz; tam indirim = ücretsiz);
  `subtotal()/grandTotal()`. **Ödeme durumu** `payment_status` (unpaid/partial/paid/waived) faturadan senkron
  (`InvoiceService::recordPayment/cancel` → `BookingService::syncPaymentStatus`, audit).
- **Alan tahsisi yarışı:** `SpaceService::assign` alan satırını kilitler, sonra kapasiteyi sayar.
- Mevcut olan ve doğrulanan: oda/alan/lokasyon CRUD + doğrulama + `geo.edit/publish` yetkisi, çalışma saatleri
  (`open_from/until`, lokasyon `opening_hours`), durum makinesi (create/approve/reject/cancel/reschedule/check-in/
  complete/no-show/expire), bildirim olayları, denetim izi, iptal bildirim süresi, onay süresi dolumu.
  Testler `OperationsHardeningTest` (4).

### 46. Envanter ekranı yeniden tasarımı — tek ekrandan masa/ofis/oda + tahsis + demirbaş ✅ (18 Eylül 2026)
`/panel/alanlar` (`SpaceController::index` okuma, `InventoryController` yazma): "lokasyona git → envanter ekle → geri dön"
adımı kalktı. Üstte **+ Envanter ekle · + Hızlı tahsis · + Demirbaş ekle** (native `<dialog>` modalları, `panel.js`
`initModals`: `data-modal-open` + `data-fill` JSON ile düzenleme, `data-when` koşullu alan, `data-filter-by` bağımlı
seçenek süzme, doğrulama hatasında modal yeniden açılır). Sekmeler **Tüm envanter · Masalar · Ofisler · Odalar ·
Demirbaşlar · Tahsisler**; durum süzgeci Müsait/Tahsisli/Bakımda/Pasif; lokasyon + arama (ad, kod, üye, şirket).
- **Kartlar:** ad, kod, tür, lokasyon, kat, alan, kapasite, durum, tahsisli üye + şirket, tahsis aralığı, aylık ücret,
  demirbaş sayısı, olanaklar, son güncelleme; eylemler Detay · Tahsis et · Düzenle · ••• (tahsisi değiştir/sonlandır,
  bu alana demirbaş ekle). Odalar aynı ızgarada (Takvim · Düzenle).
- **Envanter ekle/düzenle:** tek form; tip = masa türleri (`Space::KINDS` + `workspace` Sabit çalışma alanı, `other`
  Diğer) → `space.manage` rotası, oda türleri → `geo.edit` rotası (JS tür seçimine göre action; sunucu ayrımı route
  izniyle). Alanlar: ad, kod (tekil, `spaces.code`/`rooms.code`), lokasyon, kat, alan/bölüm, kapasite, ücret, durum
  (Aktif/Bakımda+tarih/Pasif), açıklama, olanaklar, görsel (lokasyon galerisi). Aktif tahsisi olan alan başka
  lokasyona taşınamaz.
- **Demirbaş (`assets`, `AssetService`):** ad, kod (tekil), kategori, seri no, lokasyon, yerleşik alan, durum
  (müsait/bakımda/hurda; "tahsisli" yalnız tahsisle), not. Tahsisle birlikte verilir (`asset_ids`, aynı lokasyonun
  müsait demirbaşı; `attach` satır kilidiyle), tahsis bitince/değişince serbest kalır; tahsisli demirbaş silinemez.
- **Tahsis:** hızlı tahsis (alan → şirket → üye/üyelik → başlangıç/bitiş → demirbaşlar → not);
  `SpaceService::updateAssignment` (bitiş, üye, not, demirbaş kümesi senkron); sonlandır → demirbaşlar serbest.
  Tahsisler sekmesi: kime/nereye/ne zamana kadar, 30 gün içinde bitenler işaretli.
- **Yetki/kapsam:** yazma `space.manage,anylocation` (lokasyon yöneticisi yalnız kendi lokasyonu; yabancı lokasyon
  404), oda yazımı `geo.edit`; `super_admin` matriste `space.manage` aldı. `asset_v()` sürümlü asset adresi (CSS/JS
  önbelleği). Testler `InventoryScreenTest` (2); lokasyon künyesindeki eski alan/oda formları duruyor.

### 47. Tahsilat & belge merkezi — manuel tahsilat, makbuz, geciken ödeme belgesi, şablonlar ✅ (18 Eylül 2026)
`/panel/tahsilat`: mevcut takip ekranı (KPI'lar, açık faturalar — gecikmiş önce/vadesi yaklaşan, aylık tahsilat,
yaklaşan üyelik bitişleri) **aynen korunur**; üstüne yalnız aksiyonlar eklendi (**+ Manuel tahsilat · Tahsilat makbuzu ·
Geciken ödeme belgesi · Belge şablonları**), açık fatura satırlarına "Tahsilat ekle" ve gecikmişlerde "Belge oluştur",
altına "Son tahsilatlar" (makbuz/iptal) ve "Belgeler" bölümleri. Mevcut fatura/tahsilat yapısı korunarak
genişletildi (`InvoiceService::recordPayment` aynı yol; `InvoiceController::payment` rotası duruyor).
- **Manuel tahsilat** (modal, `payment_allocation.manage`): müşteri → fatura (şirkete göre süzülür; kalan tutar,
  hizmet ve para birimi otomatik) → tutar → ödeme yöntemi (Nakit / Kart-POS / Havale-EFT / Diğer) → tarih →
  açıklama/referans → not; **Kaydet** ya da **Kaydet ve makbuz oluştur**. Faturaya (paid_amount/status) ve müşteri
  hesabına anında yansır; nakit ödeme rozetle belirtilir. `payments.currency/description/status`.
- **Tahsilat iptali** (`InvoiceService::cancelPayment`): kayıt silinmez → `cancelled` + gerekçe; fatura bakiyesi
  geri alınır (ödenmişse yeniden issued/overdue), bağlı makbuz iptal olur, rezervasyon ödeme durumu senkron,
  audit `payment.cancelled` + `invoice.payment_reversed`. Özet/aylık toplamlar iptalleri saymaz.
- **Belgeler** (`documents`, `DocumentService`): tahsilat makbuzu `MKB-YYYY-000001`, geciken ödeme belgesi
  `GOB-YYYY-000001` (`document_sequences`, satır kilidi); değerler belge anında `data`'ya dondurulur (işletme
  bilgisi site marka ayarından, müşteri/fatura/tahsilat kayıttan, tutar yazıyla `NumberWords`). Tahsilat başına tek
  geçerli makbuz. Belge sayfası: **Önizleme · Düzenle (yalnız metin alanları, audit) · PDF indir (dompdf) ·
  Yazdır (otomatik yazdırma görünümü) · İptal**. Aynı HTML önizleme/PDF/yazdırma için (`documents.render`).
- **Geciken ödeme belgesi:** açık faturalar listesindeki gecikmiş satırlardan "Belge oluştur" (`InvoiceService::overdueList`
  servis tarafında hazır).
- **Belge ayarları / şablonlar** (`document_templates`, `App\Documents\DocumentTemplates`; yazma `invoice.issue`):
  logo, başlık, alt başlık, işletme bloğu, giriş metni, tablo sütunları, gövde, imza, kaşe, alt bilgi, vurgu rengi;
  dinamik alanlar `{{customer_name}} {{invoice_number}} {{amount}} {{payment_method}} {{date}} {{due_date}}
  {{remaining_amount}} …` (tanımsız yer tutucu olduğu gibi kalır). Düzenlerken **gerçek belge önizlemesi**: son
  gerçek kayıtla (yoksa etiketli örnekle) sunucuda çizilir, yazarken JS (`initLivePreview`) anında günceller;
  "Sunucuda önizle" kaydetmeden yeniden çizer.
- `super_admin` matriste `invoice.issue` + `payment_allocation.manage` aldı. Bağımlılık: `dompdf/dompdf ^3.1`
  (uzak kaynak kapalı, chroot public/). Testler `CollectionCenterTest` (3).

### ⛔ 19–22 · 25–28 (AI, Search Console, Schema, Command Center'lar)
Temeller hazır; sıra değişmedi.

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
| F8 | SEO/GEO Command Center | 🟡 SEO v1 (`/panel/seo`) + GEO v1 (`/panel/geo`) |
| F9 | Performance + Cache Command Center | ✅ Cache v1 (`/panel/onbellek`) + Performans (`/panel/performans`: baseline, önbellek, zamanlayıcı, doctor) |
| F10 | Booking (müşteri + resepsiyon masası) | ✅ v1 (`/panel/sirketler/{company}/rezervasyonlar`, `/panel/rezervasyonlar`, odalar GEO altında) |

---

## SIRADAKİ ADIMLAR

1. **Üretime alma** — kontrol listesi ve adımlar `DEPLOY.md`'de;
   `php artisan ofisvio:doctor` kapıdır (üretimde hata → çıkış 1: APP_DEBUG,
   https, clamd canlı tarama, önbellek/oturum sürücüsü, e-posta, migrasyon,
   zamanlayıcı kalp atışı, RBAC/site seed, personel hesabı). Kalan: gerçek
   sunucu, alan adı, sertifika, clamd — sen sağlarsın, doctor doğrular.
2. **Dış servis isteyen kalemler** — faz 19–22 (AI, Search Console, analytics),
   faz 5 entegrasyon kalemleri (Integration Gateway, Secret Management, SSRF,
   Webhook Security), Redis etiket / CDN purge: kimlik bilgisi ve altyapı
   gelince. Kod tarafında 🟡 faz kalmadı.
3. **İçerik** — editör panelden yazıları, yasal sayfaları ve vitrin bloklarını
   yayınlar.

Kapıyı yeniden koşturmak için proje kökünde dört komut (yukarıda).
