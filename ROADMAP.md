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
| `php artisan test` | ✅ **335/335** (Unit 12 · Feature 307 · Architecture 16) |
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

### 48. CMS stüdyo — sayfa oluşturma/düzenleme profesyonel seviye ✅ (18 Eylül 2026)
`/panel/icerik?kind=page` listesi **aynen korunur** (süzgeç, sütunlar, Aç); yalnız **Görsel** (kapak küçük resmi),
**SEO** (kayıtta hesaplanan skor rozeti) ve **GEO** (özet + soru/SSS dolu → hazır) sütunları ile satır başına
Düzenle/Taslak eklendi. Sayfa/yazı formu (`panel/content/form`, personel + müşteri paneli aynı görünüm) sekmeli
stüdyoya dönüştü: **İçerik · Görseller · İç bağlantı · SEO · GEO/AI · Şema · Yayın**; alt çubuk **Taslak kaydet ·
Önizle · Yayınla** (kaydedilmemiş değişiklik uyarısı `beforeunload`). Depolama Markdown kalır (ham HTML süzülür).
- **Editör** (`public/js/cms.js`, bağımlılıksız): araç çubuğu H1–H6, kalın/italik, listeler, alıntı, bağlantı,
  iç bağlantı (yazarken `[[` ile canlı öneri — yayındaki sayfalardan), görsel, YouTube/video, tablo, buton/CTA,
  kod, ayırıcı, embed, **içerik blokları** (`:::hero|cta|features|faq|stats|gallery|contact|box|testimonials` …
  `:::`; `App\Content\BodyRenderer` + `site/blocks/<tür>.blade.php`, tüm alanlar kaçırılır). Kısa kodlar
  `[youtube:ID]`, `[video:url]`, `[embed:url]` (yalnız YouTube/Vimeo/Google Haritalar; CSP `frame_src`),
  `[button:Metin](/adres)`; görsel öznitelikleri `![alt](url "alt yazı"){left|right|center|full width=50%}` →
  figure + hizalama + caption. CRLF normalize edilir.
- **Görseller:** medya kütüphanesi editör içinde (içeriğe ekle → hizalama/genişlik sorulur, alt zorunlu istenir;
  kapak yap; bilgi düzenle alt/başlık/açıklama; **kırp** — canvas seçim, oran, çıktı genişliği → base64 → sunucuda
  `MediaService::uploadDataUrl` aynı karantina zinciri, **yeni** medya, orijinal korunur), yükleme alt/başlık/
  açıklama/SEO dosya adı (`seo_name` → slug.uzantı) ile editöre geri döner (`return` yalnız `/panel/` yolu).
  Medya işlemleri ayrı gizli formlarla normal POST'tur (JS→HTTP çağrısı yok).
- **İç bağlantı:** öneri listesi (yayındaki sayfalar) + ilgili sayfalar (`linkSuggestions`) tek tıkla bağlanır;
  `SeoService::linkAudit` kırık iç bağlantı / giden sayı / gelen sayı (0 = yetim; menü bağlantısı sayılır).
- **SEO sekmesi:** SEO başlığı, meta açıklama (sayaçlar), odak + ilgili anahtar kelimeler, canonical, robots
  (index/noindex × follow/nofollow), OG başlık/açıklama/görsel, SERP önizlemesi; **canlı analiz**
  (`App\Content\SeoAnalyzer`, JS aynası): başlık/açıklama uzunluğu, gövdede H1, başlık hiyerarşisi, iç bağlantı,
  görsel/alt, odak kelime (başlık/açıklama/slug/giriş/yoğunluk), uzunluk, canonical, şema, OG; ağırlıklı skor
  `contents.seo_score` kayıtta. `SeoService::head` sayfa düzeyi robots/canonical/OG'yi uygular.
- **GEO/AI sekmesi:** özet, ana konu, varlıklar, kullanıcı soruları, SSS (Soru | Cevap), kısa cevaplar, ilgili
  konular, AI arama özeti, şema önerisi (`contents.geo` JSON). Öneriler **içerikten deterministik** üretilir
  (`App\Content\GeoSuggester`: özet/başlıklar/lokasyon-hizmet adları/diğer sayfalar), "boş alanları doldur" /
  "tümünü değiştir" ile forma gelir, kaydedilmeden yazılmaz; SSS JSON-LD FAQPage'e girer.
- **Şema sekmesi:** `schema_types[]` (WebPage, Article, FAQPage, BreadcrumbList, Organization, LocalBusiness,
  Service) — seçim yoksa varsayılan; seçilirse yalnız seçilenler (+ Organization yayıncı); `schema_custom` JSON
  `@graph`'a eklenir (doğrulanır); üretilen JSON-LD önizlemesi.
- **Önizleme:** `panel.content.preview` sayfası, imzalı `site.preview.content` (`/onizleme/icerik/{id}`, 30 dk,
  yayınlanmamış içerik görünür, noindex) iframe'de **masaüstü/tablet/mobil**; çalışma taslağı `?draft=1` ile
  bellekte bindirilir (yayındaki metin değişmez). **Yayınla** `content.create|edit` + `content.publish` iki izinli
  rotalar (`store.publish`, `update.publish`) → `ContentService::publishNow` (DRAFT → IN_REVIEW → PUBLISHED, her adım
  audit; onay gerektiren içerik reddedilir).
- Taslak (`content_drafts`) aynı stüdyo alanlarını taşır ve yayınlanınca birleşir. Yeni sütunlar
  `2026_09_18_000026_cms_studio_fields`. Testler `CmsStudioTest` (7).

### 49. Görsel site editörü — `/panel/icerik/tasarim` ✅ (18 Eylül 2026)
Form listesi yerine **gerçek vitrin üzerinde** düzenleme. Mevcut mimari korunur: `site_sections` taslak → `publish`
anlık görüntü (`site_revisions`) → vitrin; `SectionLibrary` bileşenleri; dinamik bölümler gerçek kayıtlardan.
- **Çerçeve:** imzalı `site.preview?editor=1` (30 dk) iframe'de; yalnız bu modda `ofv_editor()` işaretleri
  (`data-ofv-section/-field/-global/-image/-md`) ve `public/js/site-editor-frame.js` basılır — canlı vitrine editör
  kodu gitmez (`VisualEditorTest` bunu zorlar). Aynı kökende doğrudan DOM erişimi; JS'ten HTTP çağrısı yok.
- **Üst çubuk:** Geri al · Yinele (bellekte anlık görüntü yığını, Ctrl+Z/Y) · Kaydet (Ctrl+S) · Önizle (kaydet +
  gerçek önizleme) · Masaüstü/Tablet/Mobil (çerçeve genişliği + cihaz bazlı tasarım) · Yayınla (content.publish).
- **Elemente tıkla → sağ panel:** İçerik (kütüphane şemasından alanlar, CTA, medya seçici) · Tasarım (cihaz başına
  `SectionStyle`: arka plan/renk, padding/margin, hizalama, kolon 1–4, maks. genişlik, z-index, köşe, gölge, kenarlık,
  gizle; alan başına metin biçimi: boyut/ağırlık/yazı tipi/renk/satır yüksekliği/harf aralığı/hizalama/italik) ·
  Görünürlük (görünür, mobil/masaüstü gizle, kilit, zamanlama) · SEO (çerçeveden başlık/açıklama/H1/başlık
  hiyerarşisi/iç bağlantı/alt/kelime/canonical/şema; sayfa bazlı SEO-GEO-şema CMS stüdyoya bağlı).
- **Inline metin:** `data-ofv-field` alanları contenteditable; yüzen çubuk (kalın/italik/hizalama/boyut/renk);
  global metinler (menü etiketi, CTA, hero satırları) `data-ofv-global` ile aynı yerden düzenlenir.
- **Hover araç çubuğu:** Düzenle · ↑ · ↓ · Kopyala · Gizle · Ayarlar · Sil (kilitli bölümde kapalı).
- **Sürükle-bırak:** sol palet (Temel · İçerik · Yerleşim · Gerçek veri; yeni tipler `heading image buttons divider
  spacer columns content features testimonials gallery map`) → çerçeveye bırakma çizgisi; katmanlar listesinde ve
  çerçevede bölüm taşıma; **bilgisayardan görseli sayfadaki görselin üstüne bırakma** (kaydedince `MediaService::upload`
  karantina zinciri, `upload:token` → medya id). Şablonlar `<template data-ofv-template>` ile gerçek bileşen olarak
  çizilir; ekleme sunucu çağrısız.
- **Katmanlar:** Header · bölümler (+ alan çocukları) · Footer; göster/gizle, kilit, kopyala, sil, sürükle.
- **Global alanlar:** Header/üst şerit/Footer tıklanır; metinler ve footer sütunları `websites.builder_globals`
  taslağına yazılır, önizlemede biner, **yayınlayınca** `SiteBlockService`'e geçer (tüm sayfalarda). Site ana görseli
  (hero) yalnız `website.manage` ile.
- **Kayıt:** tek `PUT tasarim/{website}/taslak` — `payload` JSON (sıra, yeni/silinen, kilit, etiket, ayarlar,
  globaller) + `uploads[token]`; `SiteBuilderService::applyDraft` her bölümü `normalizeSettings` (alan tipi + stil
  allowlist) ile doğrular, tekil tip/tip değişimi/çapa/harita kaynağı hatalarında hiçbir şey yazmaz. Kaydedilmemiş
  değişiklikte çıkış uyarısı.
- **Sayfalar:** liste (durum, SEO skoru, GEO rozeti, Önizle, Stüdyo) + **+ Yeni sayfa** (boş / hazır şablon
  `PageTemplates` / mevcut sayfayı kopyala) → CMS stüdyo. **Kayıtlı bloklar** (`site_block_presets`): "Blok olarak
  kaydet" → palette/çerçeve şablonu. **Sürümler:** revizyon listesi, "Önizle" (`?revision=N`) ve geri dön.
- **✦ AI tasarım yardımcısı:** kural tabanlı komut yorumlayıcı ("hero bölümünü daha modern yap", "bu bölüme 3 kart
  ekle", "SSS ekle", "mobilde iki kolon", "koyu yap", "görseli değiştir", "gizle/taşı") → öneri listesi →
  Uygula → çerçevede önizleme; dış AI sağlayıcı yok (bağlanınca aynı öneri arayüzü). Uydurma içerik üretmez.
- Migrasyon `2026_09_18_000027_visual_editor` (`site_sections.locked/label`, `websites.builder_globals`,
  `site_block_presets`). Testler `VisualEditorTest` (5); `SiteBuilderTest` değişmeden geçer.

### 50. Blok kütüphanesi — `/panel/icerik/bloklar` ile `/tasarim` tek düzenleme yüzeyi ✅ (18 Eylül 2026)
Aynı işlev iki yerde tekrar etmez: **düzenleme yalnız görsel editörde**. `/panel/icerik/bloklar` artık
**Blok Kütüphanesi / Şablonlar** (content.edit|publish görür):
- **Kayıtlı bloklar & şablonlar:** ad, kategori (Hero · Hizmetler · CTA · SSS · Referanslar · İletişim · Blog · Footer
  · İçerik · Özel), **🌐 global / normal**, kullanım (ana sayfa taslak/yayın sayısı), **Tasarımda düzenle** (bölüm
  sayfadaysa `?secim=`, değilse `?ekle=preset:ID` ile editörde ekler), Önizle (imzalı `site.preview?preset=ID`,
  gerçek bileşen, cihaz seçici), Kopyala, Ad/kategori/global (modal), Sil (bağlı bölümler kendi kopyasıyla kalır),
  **+ Yeni blok şablonu** (tip + ad + kategori + global → tipin varsayılanıyla oluşur, editörde açılır).
- **Hazır bileşenler:** `SectionLibrary` kataloğu; kullanım = ana sayfa bölümü + gövdesinde `:::tip` geçen sayfalar;
  Tasarımda düzenle / Tasarıma ekle / Şablon oluştur.
- **Global blok:** `site_sections.preset_id` bağı; `is_global` bloklarda ayar çizim anında bloktan okunur (yayındaki
  anlık görüntü dahil, yeniden yayın olmadan); editörde bağlı bölüm düzenlenince `applyDraft` bloğa yazar ve tüm
  bağlı bölümleri eşitler (aynı kayıtta yalnız DEĞİŞEN yazar); "Bağı kopar" bölümü kopyaya çevirir; global kapatılır
  ya da blok silinirse kullanımlar son ayarı kopya olarak alır. Normal blok eklenince kopyalanır.
- **Editör ↔ kütüphane:** editör üst çubuğunda **+ Blok ekle** aynı kataloğu (`panel.content._block-catalog`, picker
  modu) pencerede açar ("Kütüphaneyi yönet →"); Kayıtlı sekmesi kategori/global rozetlerini gösterir.
- **Eski /bloklar formları kaldırıldı:** 33 site metni editörde inline (`data-ofv-global`) ve Header panelinde
  "Diğer site metinleri" ile; veri listeleri (dahil olanlar, planlar, plan satırları, fiyat notu) editörde **ilgili
  bölüm seçilince** (`SiteBuilderService::DATA_BLOCK_SECTIONS`) düzenlenir → `builder_globals.blocks` taslağı →
  önizleme → yayınla (`SiteBlockService::update`); biçim hatası kaydı durdurur. `PUT /bloklar/metinler` ve
  `PUT /bloklar/{blok}` faz 10 servis uç noktası olarak kalır (content.publish, form yok).
- Migrasyon `2026_09_18_000028_block_library`. Menü: "Blok kütüphanesi". Testler `BlockLibraryTest` (3).

### 51. 360° üye / müşteri yönetim merkezi — `/panel/uyeler` ✅ (18 Eylül 2026)
Üye dizini (faz 39) korunur; üye = şirket üyeliği (`user_roles`), ticari kayıtlar ŞİRKET bazlıdır (fatura, tahsilat,
üyelik, tahsis şirkete kesilir) — profil kişi bilgisini taşır. Yazan yollar mevcut servislerdir; `MemberCenterService`
yalnız profil, sözleşme ve ek harcamayı yazar, kalanını birleştirir.
- **+ Yeni üye** (`membership.manage,company`; personel için system_admin/operations_admin global grant eklendi):
  ad, soyad, e-posta (davet + şifre bağlantısı), telefon, firma (seçim), ünvan, TC/vergi no (maskeli), adres, şehir,
  ülke, üyelik tipi, üyelik başlangıcı, ilk sözleşme (tür/başlangıç/bitiş), durum, not, profil görseli (medya
  karantina zinciri). `member_profiles` + otomatik üye no `UYE-000001`.
- **Liste:** üye, firma, üyelik tipi, sözleşme durumu, borç, bakiye, son ödeme, sözleşme bitişi, son güncelleme,
  durum; süzgeçler aktif/pasif/borçlu/bakiyesi olan/sözleşmesi yaklaşan (30 gün)/biten/yeni (30 gün); arama ad,
  e-posta, firma, telefon, üye no. Şirket başına finans tek geçişte (`financeByCompany`, N+1 yok).
- **360° profil** `/panel/uyeler/{üyelik}`: üst kartlar Borç · Ödenen · Bakiye · Aktif hizmet · Tahsis · Sözleşme
  (gün) · Son ödeme; otomatik uyarılar (gecikmiş ödeme, açık borç, sözleşme ≤15 gün / süresi doldu, hizmet ≤15 gün,
  iade bekleyen demirbaş); hızlı işlemler Tahsilat ekle · Ek harcama · Fatura oluştur · Sözleşme ekle · Hizmet ekle ·
  Tahsis et · Demirbaş ekle · Belge oluştur · Düzenle (yetkiye göre). Sekmeler: **Özet** (önemli tarihler, kişi, son
  hareketler/aktivite) · **Finans** (toplam borç, ödenen, kalan, bakiye, bekleyen, geciken, son/toplam tahsilat; açık
  faturalar; hareket geçmişi: +tahsilat / −fatura / −faturasız ek harcama, belge bağlantısı, oluşturan) ·
  **Sözleşmeler** (`contracts`: SOZ-YYYY-000001, tür, tarihler, durum draft/active/ended (+ türetilmiş expired), dosya
  PDF/JPG/PNG özel diskte, not; Yeni/Düzenle/PDF (belge motoru `contract` türü, `documents.contract_id`)/İndir/
  Sonlandır gerekçeli) · **Tahsisler** (masa/ofis/oda + demirbaşlar; tahsis et / demirbaş ekle / bitir) · **Hizmetler**
  (üyelikler: plan, tarihler, ücret, durum, yenileme) · **Belgeler** (sözleşme, fatura, makbuz, gecikme belgesi tek
  listede) · **Aktivite** (denetim izinden kim/ne/ne zaman: üye, sözleşme, hizmet, tahsis, demirbaş, ödeme, harcama,
  belge).
- **Ek harcama** (`extra_charges`, `invoice.issue,company`): tür (ek toplantı odası, baskı, kargo, telefon, ek hizmet,
  hasar, demirbaş, diğer), açıklama, tutar, para birimi, tarih, not; faturalama: yeni fatura kes ve yayınla
  (InvoiceService) / faturasız (bakiyeye doğrudan) / taslak faturaya bağla. Faturalanan harcama çift sayılmaz.
- **Entegrasyon:** tahsilat, makbuz, gecikme belgesi, fatura, üyelik, tahsis, demirbaş ve tahsis bitirme mevcut
  rotalara gider; `App\Support\PanelReturn` ile `return` (yalnız `/panel/` yolu) profile döner. Tahsilat modalı
  `collections/partials/modal-payment` paylaşımlı.
- Migrasyon `2026_09_18_000029_member_center`. Testler `MemberCenterTest` (3).

### 52. Üretim denetimi — çalışmayan fonksiyon yok, güvenlik, veri bütünlüğü, API merkezi ✅ (18 Eylül 2026)
Tarama araçları koda test olarak eklendi (her koşuda yeniden denetler):
- **`PanelSmokeTest`**: parametresiz her panel GET rotası (52) beş rol için 500 vermeden açılır; kimliksiz istek her
  rotada girişe yönlenir. **`IdorProbeTest`**: şirket sahibi başka şirketin/organizasyonun fatura, makbuz/PDF,
  üyelik, üye profili, sözleşme dosyası, şirket sayfası, tahsilat/ek harcama/düzenleme rotalarına id değiştirerek
  ulaşamaz (403/404), global personel görür. **`CriticalFlowsTest`**: üye → hizmet → sözleşme → tahsis(+demirbaş) →
  fatura → tahsilat → makbuz uçtan uca (negatif/aşan tutar reddi, tahsis bitince demirbaş iadesi, tahsilat iptali
  fiziksel silmez, 13 audit olayı, aktivite izi) ve blog → SEO/GEO/şema → iç bağlantı → yayın (head, Article +
  FAQPage JSON-LD, sitemap, gelen bağlantı, skor/GEO rozeti). **`SystemCenterTest`**: API merkezi maskeleme, test
  bağlantısı, sağlık, yetkiler, DomainException güvenlik ağı. Rota referans taraması: tanımsız `route()` yok.
- **Audit log tamamlandı**: `AuditService` aktör verilmezse oturumdaki kullanıcıyı yazar; eklenen olaylar
  `staff.invited role.assigned role.suspended role.reactivated membership.invited membership.suspended
  membership.reactivated content.created content.updated content.deleted content.status_changed location.created
  location.updated location.published location.deleted website.* company.created company.updated
  integration.tested system.health_checked` (tahsilat/fatura/tahsis/demirbaş/belge/sözleşme/ek harcama/ayar zaten
  vardı; giriş/çıkış `login_events`). IP + user agent her kayıtta.
- **Veri bütünlüğü**: lokasyon silme koruması (alan/oda/rezervasyon/demirbaş varsa cascade ile geçmiş silinmez →
  pasife alma); `Media::isInUse` OG görseli, üye avatarı, hizmet ve alan kapağını da sayar; eksik indeksler
  (`documents company_id+created_at`, `user_roles company_id+status`, `payments invoice_id+status`,
  `contents parent_id`, `extra_charges invoice_id`, `contracts status+ends_on`); üye dizini avatar N+1 giderildi.
- **Hata yönetimi**: yakalanmayan `DomainException` forma anlaşılır mesajla döner (JSON 422); GET'te genel hata
  sayfası (ayrıntı log'da). Hata sayfaları 403/404/419/429/500/503 mevcut; APP_DEBUG durumu Sistem sağlığında.
- **Frontend**: form gönderiminde düğmeler devre dışı + `aria-busy` (çift tıklama koruması, geri gelince sıfırlanır;
  `data-no-busy` ile kapatılır).
- **API & Entegrasyonlar** `/panel/ayarlar/api` (`settings.view`; test `settings.manage`): kategori bazlı
  (çekirdek, e-posta, SMS & WhatsApp, ödeme & e-Fatura, AI, SEO & Analytics, depolama & güvenlik, webhook);
  projede gerçekten kullanılan sağlayıcılar (`config/integrations.php` + veritabanı/önbellek/kuyruk/zamanlayıcı/
  e-posta/depolama/ClamAV/webhook). Secret'lar **yalnız env** (Integration Gateway kuralı; ArchitectureTest), panelde
  maskeli + env adı; **Bağlantıyı test et** gerçek yoklama (`ConnectionTester`: Gateway/SSRF korumalı istek, DB,
  önbellek, kuyruk sayaçları, SMTP soketi, disk yazma, clamd, zamanlayıcı kalp atışı) → `integration_logs` + audit.
  Kullanılmayan servisler (Mapbox, S3, OAuth) sahte alan olarak eklenmez.
- **Sistem sağlığı** `/panel/ayarlar/saglik`: `ofisvio:doctor` kontrolleri (tek kaynak) Çalışıyor/Uyarı/Hata
  etiketleriyle + son bağlantı testleri; "Yeniden kontrol et".
- Taramada doğrulananlar (değişiklik gerekmedi): kod/yapılandırmada gömülü secret yok; CSP/HSTS/X-Frame/Referrer
  başlıkları; login/2FA/JIT/webhook/yükleme hız sınırları; CSRF; oturum çerezi bayrakları doctor'da; yüklemeler
  MIME + sihirli bayt + boyut + ClamAV + sha256, SVG/HTML reddi, dosya adı slug, özel disk; finans tutarları
  sunucuda (kuruş), negatif/aşan tutar reddi; markdown `html_input=strip`; ham `<head>` kodu yalnız JIT'li
  Geliştirici sekmesi; silme korumaları (alan, demirbaş, plan, hizmet, site, medya); mass assignment `$fillable`.
- Migrasyon `2026_09_18_000030_audit_indexes`. Testler +6 (PanelSmoke 1, IdorProbe 1, CriticalFlows 2, SystemCenter 2).

### 53. Ana sayfa: boş görseller, tek lokasyon (Konya) modu, franchise / iş ortaklığı ✅ (18 Eylül 2026)
- **Boş görsel alanları**: medya kütüphanesi boşken hero, hizmet kartları, şube kartı/öne çıkan şube, yazı kapağı ve
  franchise bölümü marka diline uygun SVG illüstrasyon setiyle dolar (`public/images/illustrations`, palet =
  tasarım sistemi; `App\Site\Illustrations`: anahtar → dosya/boyut/alt metni, hizmet slug/ad eşlemesi;
  `site.partials.illustration`). Gerçek görsel (`Media`) varsa o basılır, illüstrasyon kalkar; `shot__note` yer
  tutucu metinleri vitrinden kalktı (editörde "site görseli yükle" işareti sürüyor). Her görsel `width/height/alt/lazy`.
- **Tek lokasyon modu** (`SiteBlockService::singleLocation`, istek başına memo `ContentCache::memo`): yayında + aktif
  tam bir şube varsa — bölge seçimi, "Tüm bölgeler", bölge sekmeleri, boş lokasyon kartları ve lokasyon/şehir/bölge
  sayımı yok; hero'da şube rozeti, `locations` bölümü **öne çıkan şube** (kapak/illüstrasyon, adres, ilçe/posta kodu,
  telefon, çalışma saatleri, hizmet etiketleri, fiyat notu, yol tarifi yalnız koordinat varsa, şube sayfası + teklif
  CTA), istatistikler şubenin verisi (çözüm/oda sayısı), header/footer bölge listesi yerine şube; **hiçbir alan
  uydurulmaz** (boş alan basılmaz). Metinler şehre göre okunur: `config('ofisvio.texts_single')` `{city}`/`{city_da}`
  (`App\Support\TurkishSuffix::locative` — ünlü uyumu + sertleşme, kesme işareti), panelde kaydedilen metin yine
  kazanır; ana sayfa `<title>`/açıklama şehir + DB adresiyle (SiteLayoutComposer). Çoklu lokasyonda görünüm değişmez.
- **Franchise / İş ortaklığı bölümü** (`SectionLibrary` `franchise`, grup içerik, tekil; alanlar üst etiket/başlık/
  açıklama/maddeler/CTA; varsayılan "Markamızı birlikte büyütmek ister misiniz?" + **Franchise Başvurusu** → `/franchise`).
  Varsayılan yerleşimde teklif formundan önce; mevcut sitelere migrasyon `000032` taslağa + yayınlanmış son revizyona
  yeni revizyon olarak ekler (ayarsız = varsayılan metin, ticari rakam yok). Editörde satır içi düzenlenir.
- **Franchise sayfası** `/franchise`: H1/H2, breadcrumb, süreç adımları, SSS; alanlar Ad, Soyad, Firma (isteğe bağlı),
  Telefon, E-posta, Şehir, İlçe, Yatırım bütçesi (**seçim listesi** `FranchiseApplication::BUDGETS`), İşletme
  deneyimi, Mesaj, KVKK; bal küpü + `throttle:5,1` korundu. Head `SiteLayoutComposer` (`site.franchise`, kanonik
  `/franchise`), JSON-LD WebPage + BreadcrumbList + FAQPage (yalnız süreç bilgisi; lokasyon uydurulmaz).
- **Mevcut franchise yapısı genişletildi** (yeniden kurulmadı): `franchise_applications.number` (**FR-YYYY-000001**,
  yıl bazlı sıra, tekil), `first_name/last_name/company`; durumlar `new İnceleniyor→reviewing meeting positive
  negative archived` (Yeni · İnceleniyor · Görüşme · Olumlu · Olumsuz · Arşiv; eski approved/rejected migrasyonla
  positive/negative). `FranchiseService::apply` numara üretir, audit `franchise.applied` yazar, bildirim
  `franchise.applied` (in_app + e-posta, CRM grubu; konu/gövdede `{{number}}`). Panel `/panel/franchise` liste
  sütunları Başvuru no · Ad Soyad · Firma · Telefon · E-posta · Şehir · Bütçe · Tarih · Durum; detayda tüm alanlar +
  sorumlu + iç not; operasyon panosu KPI etiketleri güncellendi.
- Sorgu bütçesi: vitrin/yazı sayfası +1 (tek lokasyon tespiti, istek başına bir kez); blog kartı kapağı
  `cover_url` (ilişki sorgusu yok). Migrasyonlar `000031_franchise_number_company_statuses`,
  `000032_franchise_home_section`. Testler +4 (`FranchiseHomeTest`: Türkçe ek/illüstrasyon birim, tek lokasyon ana
  sayfa, çoklu lokasyon korunur, başvuru → doğrulama → DB → bildirim → panel liste/detay/durum → hız sınırı).
- **Üst şerit düzenleme (53b)**: lokasyon sayısı metni `texts.topbar_count` (`{count}` = yayındaki şube sayısı, boş =
  gizli; editörde satır içi + Header paneli), telefon/WhatsApp/e-posta editörden (`globals.contact` → site ayarı,
  yalnız `website.manage`, audit `website.settings_updated`; üst şeritte telefon satır içi `data-ofv-site-field`).
  `TopbarGlobalsTest` (+1).
- **"Gün Geçişi" → "Günlük Kullanım"** (tüm alanlar): hizmet adı + slug (`gunluk-kullanim`), referans veri
  (`services.json`, `site_blocks.json` plan/footer), illüstrasyon anahtarı, üye profili üyelik tipi anahtarı;
  migrasyon `000033` mevcut kayıtları taşır ve `/cozum/gun-gecisi` için 301 yönlendirme kuralı ekler.

### 54. Akıllı URL / yönlendirme yönetimi — 404 karar zinciri, URL geçmişi, benzerlik, bot ✅ (18 Eylül 2026)
- **Karar zinciri** (yalnız vitrin GET): ayar yönlendirmeleri → **yönlendirme tablosu** `url_redirects` (slug değişimi /
  silme / manuel / onaylı öneri; zincir çözülmüş hedef, isabet sayacı; `SiteSeoPolicy`) → 404'te `RedirectService::onNotFound`:
  benzerlik ≥ otomatik eşik (varsayılan %85) → 301 kur + yönlendir · onay eşiği (%60) ile arası → **pending öneri** + 404
  sayfasında "Belki aradığınız" · eşik altı ve adres **URL geçmişinde gerçekten var olmuşsa** → ayara göre ilgili kategori /
  `/cozumler` / `/lokasyonlar` ya da ana sayfa (`redirect.fallback`) · rastgele adres → düz 404 (ana sayfaya soft-404 yok).
  Yayından geçici kaldırılan (taslak) içerik için kalıcı yönlendirme kurulmaz, yalnız öneri; yeniden yayında o yoldan çıkan
  kayıtlar silinir. Uzantılı bot yolları (.php/.env) günlüğe/yönlendirmeye girmez.
- **Benzerlik** `App\Seo\RedirectMatcher` (deterministik, dış servis yok): slug (.40, kelime + karakter), başlık (.25),
  kategori (.10), etiket (.10), ana konu metinde (.10), tür (.05); Türkçe ASCII katlama, gereksiz kelime ve kaba ek soyma.
  Adaylar: canlı yazı/sayfa, aktif hizmet, yayındaki lokasyon, kategori sayfaları. Kaynak özellikleri: silme anlık görüntüsü
  → URL geçmişi → çöpteki/yayından kalkmış içerik → yalnız yol. Eşikler ve varsayılan kod ayarlardan
  (`Gelişmiş SEO › Yönlendirme & 404`: `redirect.auto_threshold`, `review_threshold`, `default_code`, `fallback`, `log_404`).
- **URL geçmişi** `content_url_history` (içerik/hizmet/lokasyon; slug_change · parent_change · deleted · unpublished ·
  archived; silinen kaydın başlık/kategori/etiket/özet anlık görüntüsü). Slug ya da üst sayfa değişince eski → yeni **otomatik
  301** (`UrlHistoryService::recordMove`; alt sayfalar dahil; geri alınan slug döngü üretmez).
- **Silmeden önce onay** (`/panel/icerik/{id}/sil`, `/panel/hizmetler/{slug}/sil`, `/panel/geo/lokasyon/{slug}/sil`):
  "Bu URL için yönlendirme oluşturulsun mu?" — Yönlendir (öneri + skor + neden) · Farklı URL seç · Yönlendirme oluşturma.
- **Zincir/döngü**: `UrlHistoryService::save` hedefi son hedefe düzleştirir (A→B, B→C ⇒ A→C), kaynağa gelenleri yeni hedefe
  çevirir, A→B→A'yı reddeder; yalnız buradan yazılır (audit `redirect.created/updated/deleted`).
- **Panel** `/panel/seo/{site}/yonlendirmeler` (menü *Yönlendirmeler & 404*, rozet = bekleyen öneri; seo.view/seo.edit/
  seo.audit): istatistik kartları (toplam, açık 404, çözülen, bekleyen öneri, chain, loop); sekmeler Yönlendirmeler (Eski URL ·
  Yeni URL · 301/302/307/308 · Durum · Not; düzenle/sil), Öneriler (onayla/hedef değiştir/reddet), **404 günlüğü**
  `not_found_logs` (URL, ilk/son görülme, hit, referer, önerilen hedef + skor, durum; hit öncelikli; yönlendir/yok say),
  URL geçmişi, **Kırık URL / Redirect Botu** (404'ler, yönlendirilmemiş eski adresler, redirect chain/loop, yanlış hedef,
  ana sayfaya yönlendirme, kırık iç bağlantı, yönlendirme üzerinden iç bağlantı, isteğe bağlı dış bağlantı yoklaması
  `Gateway::probe` (SSRF korumalı, ≤25), sitemap'te yönlendirilen adres, canonical uyumsuzluğu; Kritik/Uyarı/Öneri/Düzeltildi;
  tek tıkla yönlendir / düzleştir / sil).
- **SEO/GEO**: sitemap yönlendirilen adresleri dışlar (silinen zaten çıkar); içerik canonical'ı yönlendirilen yola bakıyorsa
  hedefe çözülür; 404 sayfası yalnız mevcut içerikleri önerir. Migrasyon `000034_url_redirects`. `RedirectSystemTest` (+6):
  eşleştirici, slug değişimi/geri alma, silme onayı üç seçenek + geçersiz hedef, hizmet/lokasyon silme, 404 zinciri
  (otomatik/öneri+onay/üst kategori/düz 404/günlük/yok say), manuel yönetim + zincir + döngü + sitemap + canonical + bot + yetki.

### 54b. Editör kayıt bütünlüğü düzeltmesi ✅ (18 Eylül 2026)
- **Kök neden**: PHP boş ayar dizisi `[]` JSON'da JS dizisi oluyor; satır içi düzenlemede diziye atanan alan (`settings.title`)
  `JSON.stringify`'da düşüyordu → "Kaydet" hiçbir bölüm metnini yazmıyordu (yalnız daha önce ayarı olan bölümler). Ayrıca çerçeve
  yeniden yüklenince global metin (`applyGlobalText`) bölüm alanını eziyor, `innerText` eyebrow'u BÜYÜK HARFE çeviriyor,
  `normalizeSettings` boş CTA'yı `none` yazınca franchise bölümünün varsayılan düğmesi kayboluyordu.
- **Düzeltme**: JS `fixSection/asObject` (yükleme, geri alma, ekleme, erişim), config boş ayarı `{}` basar, global metin bölüm
  değerini atlar, dönüştürülmüş öğede `textContent`; varsayılan yerleşim/ilk açılış `SectionLibrary::defaults` ile yazılır;
  migrasyon `000035` mevcut boş franchise bölümlerine (taslak + son yayın) varsayılanı verir. Tarayıcıda 13 satır içi alan +
  panel girdisi + global metin → kaydet → yayınla → vitrin doğrulandı; `EditorSaveIntegrityTest` (+1).

### 55. Ana sayfa Lokasyonlar bölümü — tek lokasyon tasarımı (Konya) ✅ (18 Eylül 2026)
- Gösterim kuralı veritabanından: yayında + aktif şube sayısı **1** → görsel ağırlıklı tek lokasyon bloğu; **> 1** → mevcut çoklu
  tasarım (kartlar + bölge sekmeleri). Frontend'de şehir sabitlenmedi; ikinci şube yayına girince otomatik çoklu moda döner.
- Tek lokasyon bloğu ([locations.blade.php](resources/views/site/partials/locations.blade.php)): büyük şube görseli (galeri kapağı;
  yoksa marka illüstrasyonu, alt metni şehir + hizmetler), köşede ŞEHİR · ilçe rozeti, büyük ŞEHİR başlığı, editörde düzenlenen
  alt başlık (`texts.locations_title`, tek lokasyon varsayılanı "İşinizin merkezinde, profesyonel çalışma alanınız."), şube
  açıklamasının ilk paragrafı, Adres · Ulaşım · Çalışma saatleri · Telefon (yalnız dolu alanlar), hizmet etiketleri, CTA
  **Lokasyonu İncele** + **Yol Tarifi Al** (koordinat varsa koordinata, yoksa gerçek adrese; ikisi de yoksa düğme yok).
- Şehir tanıtım metni (58b): büyük şehir adının altında kısa, editörde satır içi düzenlenen metin (bölüm alanı `blurb`, boşsa
  `texts.locations_blurb`; tek şube varsayılanı `{city}` ile şehrin iş dünyasındaki yerini anlatır, boş bırakılırsa gizlenir).
- Yeni alan `locations.transport` (Ulaşım bilgisi; panel Lokasyonlar › varlık formu; şube sayfasında da görünür + yol tarifi).
  `Location::fullAddress()/directionsUrl()`. Migrasyon `000036_location_transport`.
- SEO/GEO: tek lokasyon modunda ana sayfa JSON-LD'ye şubenin **LocalBusiness** düğümü (adres, telefon, saat, koordinat,
  hizmet teklifleri) eklenir (`GeoService::localBusinessNode`, şube sayfasıyla ortak). Testler güncellendi (FranchiseHomeTest).

### 56. Hero — lokasyon sayısına göre dinamik süzgeç ✅ (18 Eylül 2026)
- Kural veritabanından: yayında + aktif şube **1** → Şehir/Bölge alanı ve şehir etiketi basılmaz; şube arka planda kullanılır
  (`data-hero-location`, teklif formunda gizli `location_id`); alt satır `texts.hero_match` (tek lokasyon varsayılanı
  "{city_da} ihtiyacınıza uygun çalışma alanını keşfedin.", boş = gizli; editörde düzenlenir). Şube **2+** → Şehir/Bölge seçimi
  (bölge seçenekleri `data-location-ids`), seçim lokasyon kartlarını süzer, bölgede tek şube varsa teklif formunun lokasyonu
  da o olur. Frontend'de şehir/sayı sabitlenmedi.
- Çözüm ve Kişi alanları aynen; forma aktarım artık kart listesinden bağımsız `initHeroFilter` (tek lokasyonda da çalışır).

### 56b. Şube seed'i üretimden çıkarıldı ✅ (18 Eylül 2026)
- `DatabaseSeeder` artık `LocationSeeder`'ı çağırmaz: 14 örnek şube (İstanbul/Ankara/İzmir…) yalnız test fixture'ıdır; üretime
  seed edilseydi vitrin çoklu lokasyon moduna düşüyordu. Gerçek şube panelden açılır (Lokasyonlar › Yeni); geliştirme DB'si
  Konya-tek şube durumuna çekildi. `Location::directionsUrl` sokak adresi yoksa (yalnız şehir) bağlantı üretmez.

### 57. Görsel editör kapsama: "Nasıl çalışır" tam düzenlenebilir, tekrarlı madde düzenleyici, kapsama denetimi ✅ (18 Eylül 2026)
- **Kök neden**: Nasıl çalışır bölümü adımlarını koddan (`ActivationJourney`) basıyordu; editörde yalnız başlık alanı vardı, açıklama
  satır içi işaretli değildi, bilgi kutusu görünümde sabitti. Adımlar/görseller/bilgi kutusu/CTA bölüm ayarına taşındı
  (`steps` satırları "İkon | Başlık | Açıklama", `images` medya listesi, `note`, `cta`); varsayılan adımlar yine aktivasyon
  akışından (`SectionLibrary::journeyDefaults`), migrasyon `000037` mevcut bölümlere (taslak + son yayın) yazar.
- **Tekrarlı madde düzenleyici** (sağ panel, `lines` alanı + `columns`): satır başına sütunlu girdiler, **+ Adım/Madde ekle**,
  **Sil**, **sürükle-bırak sıralama**; saklama biçimi değişmedi. Çerçevede madde hücreleri satır içi düzenlenir
  (`data-ofv-item="alan:satır:sütun"`, `ofv_item()` yardımcısı) — Nasıl çalışır adımları, Özellikler, Referanslar, SSS,
  Franchise maddeleri. Adım görseli tıklanınca medya seçici/yükleme (`data-ofv-image="images[]"`).
- Blog bölümüne açıklama + kategori süzgeci; SSS editörde açık basılır.
- **Kapsama denetimi** `EditorCoverageTest`: kütüphanedeki HER bölüm tipi taslağa eklenir, çerçevede seçilebilirlik ve tanımlı
  her metin/markdown/görsel/madde alanının satır içi işareti doğrulanır; rapor (Component | Frontend | Editor | Düzenlenebilir)
  test çıktısına basılır — 25/25 ✓. Nasıl çalışır: sırala/sil/ekle/görsel/not/CTA → kaydet → önizleme → yayınla → vitrin.

### 58. Blog / İçerikler bölümü + içerik ekosistemi ✅ (18 Eylül 2026)
- **Ana sayfa bölümü** ([blog.blade.php](resources/views/site/partials/blog.blade.php)): "Güncel İçerikler" + açıklama (metinler
  `blog_title`/`blog_lede`), gerçek kapaklı kartlar (kategori, başlık, özet, tarih, okuma süresi, Devamını Oku), CTA **Tüm
  Yazıları Gör**; görünüm `grid` | `spotlight` (ilk yazı büyük). Veri yalnız yayındaki yazılar: `ContentService::homePosts`
  (öne çıkanlar `contents.is_featured` önce + en yeniler, önbellekli) → yeni yazı yayınlanınca otomatik listelenir.
- **Editör**: bölüm alanları başlık/açıklama/adet/kategori/kart görünümü/CTA (+ stil sekmesiyle arka plan); kartlar çerçevede
  seçilebilir (`data-ofv-card`), panelde yayındaki yazı listesi (öne çıkan ★, kapaksız uyarısı) → yazı düzenleme ekranına.
  Yazı formunda **Öne çıkan yazı** kutusu (taslak beklemeden içeriğe yazılır).
- **İçerik ekosistemi** `php artisan ofisvio:blog-starter [--draft] [--user=]`: 14 yazı (sanal ofis nedir/avantajları/kimler
  için, hazır ofis nedir/avantajları, şirket kuruluşunda adres, profesyonel adres, girişimciler, freelancer, toplantı odası
  avantajları, coworking mi hazır ofis mi + yerel: {city}'da sanal ofis / çalışma alanları / toplantı odası) — her biri meta
  başlık/açıklama, odak + ilgili anahtar kelimeler, H2/H3, GEO özeti + 3 SSS, Article+FAQPage+Breadcrumb şeması,
  **marka kapak görseli** (`App\Content\CoverArtist`, GD ile 1200×750 PNG → medya kütüphanesine gerçek `Media`, alt/başlık),
  iç bağlantılar yalnız var olan sayfalara (hizmet slug'ları, tek şube yolu, diğer yazılar; yoksa üst liste). Yerel yazılar
  yalnız tek şube yayındaysa kurulur; Konya diğer yazılara zorlanmaz. Yinelenebilir (slug varsa atlar).
- Veri onarımı `000039`: eski blog bölümlerine yeni varsayılanlar; etkin varsayılana eşit metin ezmeleri temizlenir
  (`SiteBlockService::defaultTexts` — kaydetme artık şehir bağlamlı varsayılanı ezme olarak saklamaz).
- `BlogEcosystemTest`: komut → kapak/SEO/GEO → yayın → ana sayfa (öne çıkanlar) → detay (JSON-LD, SSS, kapak) → tüm iç bağlantılar
  200 → editör ayarları (adet/kategori/spotlight/CTA) vitrine → yeni öne çıkan yazı otomatik listede.

### 59. Canlı düzenleme (Admin Live Edit) — görseli yerinde değiştir ✅ (18 Eylül 2026)
- **Yalnız yetkili oturum**: `SiteLayoutComposer::liveEdit` sunucu tarafında karar verir (website.manage / content.edit /
  service.manage / geo.edit'ten biri); ziyaretçiye düğme, işaret, CSS/JS **hiç gitmez** (test). Oturum açık yanıt zaten
  `private, no-store` (PublicCacheHeaders) — işaretli HTML önbelleğe/CDN'e düşmez. Editör çerçevesiyle karışmaz.
- **`✎ Düzenleme Modu`** düğmesi (sağ alt): oturum bayrağı form POST ile açılır/kapanır; açıkken hedef görseller
  (`data-le="kind:id:field[:index]"` + etiket + mevcut medya + alt önerisi — `ofv_le()` yardımcısı, `picture`/`illustration`
  partial'ları `le` alır) kesikli çerçeve + hover etiketi ("Hero → Ana görsel", "Lokasyon → Konya görseli"). Tıkla → modal:
  kütüphane (arama, önizleme), yükle, sürükle-bırak (sayfadaki görselin üzerine de: onay → modal), **Kaldır**, alt metin /
  açıklama (öneri içerik bağlamından: şube şehri + "çalışma alanı", hizmet/yazı adı), **Geri al / İptal** (önceki görsele döner;
  kaydedilmeyen değişiklik yenilemede kalmaz). Seçim sayfada anında görünür; **Kaydet** tek form gönderimidir (JS'ten HTTP
  yok) → gerçek kayıt → yenilemede kalıcı.
- **Gerçek veri** (`LiveEditService`, her yazma audit `live.image_replaced`): hero `websites.hero_media_id` · bölüm görselleri
  taslak + yayınlanmış son revizyon birlikte (diğer taslak değişiklikleri yayınlanmaz; anlık görüntüler artık `id` taşır) ·
  içerik kapağı (yazı/sayfa; ana sayfa kartı, liste ve sayfa aynı kayıt) · hizmet kapağı · lokasyon kapağı (galeri kuralı:
  görsel galeriye eklenir, kapak olur). Rotalar `/panel/canli/*` panel zincirinde (auth → account.active → verified →
  staff.2fa), yetki hedef türüne göre route middleware'ında; dönüş adresi yalnız site içi yol; medya sahipliği (site) serviste.
- Kapsam: ana sayfa (hero, çözümler, lokasyon, nasıl çalışır adımları, blog kartları, görsel/galeri bölümleri), hizmetler +
  hizmet sayfası, lokasyonlar + şube sayfası, blog listesi/kategori/etiket + yazı sayfası (boş kapak için "Kapak ekle" yuvası).
  Header/footer görsel taşımaz (marka metin). Mimari diğer elementlere açık: yeni hedef türü = servis metodu + rota + `ofv_le`.
- `LiveEditTest` (+2): ziyaretçi sızıntısı yok, yetkisiz düğme/API yok, hero → blog → hizmet (yükleme) → lokasyon (galeri) →
  bölüm görseli (taslak yayınlanmaz) → kaldır → yenilemede kalıcı; dönüş adresi güvenliği. Sorgu bütçesi: yalnız oturum açık
  kullanıcıda +2 (yetki kararı).

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
