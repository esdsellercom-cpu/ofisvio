# OFISVIO — Frontend ↔ Backend Entegrasyon Denetimi (16 Eylül 2026)

Yöntem: kod tabanı tarandı, bulgular reproduce edildi, kök neden düzeltildi,
test yazıldı, kapı (pint · phpstan · test · build) koşuldu, yeniden tarandı.
Doğrulanamayan her kalem **UNKNOWN** olarak işaretlendi; tahminle PASS verilmedi.

## 0. Mimari sapma (önce bunu okuyun)

Görev metni Inertia + Vue 3 + TypeScript + Tailwind + MySQL varsayıyor. Kod tabanı
**bilinçli olarak** başka bir yığındadır ve bu denetimde değiştirilmedi:

| Beklenen | Gerçek | Sonuç |
|---|---|---|
| Inertia / Vue / TypeScript | **Blade (sunucu taraflı) + bağımlılıksız JS** (`public/js/ofisvio.js`, 200 satır: menü, filtre, form kilidi, harita) | Ayrı bir API/JSON sözleşmesi yok; "frontend" HTML formlarıdır. Sözleşme denetimi bu yüzden *route adı ↔ tanım*, *form action ↔ rota*, *form alanı ↔ FormRequest* düzeyinde yapıldı (bkz. §6). TypeScript arayüz uyumu: **N/A**. |
| Tailwind | `public/css/ofisvio.css` tasarım sistemi; Tailwind yalnız Vite giriş dosyasında kurulu, kullanılmıyor | Sapma, risk değil. |
| MySQL 8 / MariaDB | Geliştirme SQLite; üretim `DEPLOY.md` ile MySQL/PgSQL | Migration'lar sürücü bağımsız; CI SQLite. **Üretim DB'sinde koşulmuş test yok → UNKNOWN**. |
| iyzico, e-Fatura, e-imza, SMS, AI, Search Console | **Yok** (modüller yazılmadı; faz 19–28) | "Frontend doğrudan sağlayıcıya bağlanmıyor" trivially PASS; entegrasyon geçidi **yok** → UNKNOWN/N/A. |
| Booking, Invoice, Payment, Subscription, Cargo | **Yok** — vitrindeki "rezervasyon" aracı bir **ön talep** kaydıdır (`leads`), kod bunu açıkça söyler | Bu modüllerin CRUD/DECIMAL denetimi **N/A**. |

Yığın değişikliği (Vue/Inertia'ya geçiş) bu denetimin kapsamında değildir; ürün kararıdır.

## A. Executive summary

| Alan | Sonuç | Kanıt |
|---|---|---|
| Frontend/Backend Integration | **PASS** | Tüm formlar adlandırılmış rotaya POST eder; `ApiRouteConsistencyTest` görünümdeki her `route()` adının tanımlı olduğunu ve `action="/sabit"` bulunmadığını doğrular |
| Mock Data | **PASS** | `MockDataDetectionTest`: app/resources/routes/public/js'te mock/dummy/fake/demo veri yapısı 0 |
| LocalStorage | **PASS** | 0 kullanım (`localStorage|sessionStorage|indexedDB|document.cookie`); testle kilitli |
| Hardcoded Data | **PASS (düzeltildi)** | Fiyat/çözüm/oda/plan/footer/iletişim `config/ofisvio.php`'den `site_blocks` + `websites`'a taşındı (`SiteBlockSeeder`); config'te ₺/telefon/e-posta 0, testle kilitli |
| Admin CRUD | **PASS** (bkz. §E) | 226 test; eksik olan Talepler ekranı ve içerik silme/arama/sayfalama eklendi |
| Customer CRUD | **PASS** | Şirket, KYC yükleme, üyeler, site içeriği/taslak/menü/SEO/ayar/tema — testli |
| Authentication | **PASS** | Fortify + oturum; şifre `hashed` cast; kayıt kapalı; personel 2FA zorunlu; `ErrorHandlingTest` gerçek login/yanlış şifre |
| Authorization | **PASS** | Her panel rotası `auth` + `permission:`/`tenant` taşır (`ApiRouteConsistencyTest::panel_rotalari_...`); `Gate::before` yasak; frontend'de rol kontrolü yok |
| Tenant Isolation | **PASS** | `TenantIsolationTest`: A kullanıcısı B'nin 18 kaynağına GET/POST/PUT/DELETE/indirme → hepsi 404/403, veri değişmedi; context değiştirme reddedilir; vitrin siteleri ayrı |
| Database Persistence | **PASS** | Her CRUD testi `fresh()`/yeniden GET ile DB'den okur; `RefreshDatabase` |
| API Contract | **PASS** (Blade düzeyinde) | Rota adı/form action/FormRequest; JSON API yok |
| Type Contract | **N/A** | TypeScript yok; finansal DECIMAL: finans modülü yok (`locations.price_from` gösterim metni) |
| Cache Isolation | **PASS** | `CacheEngineTest`: site başına sürümlü anahtar, başka siteye dokunmaz; isabet/ıskalama/geçersizleme/ısıtma testli |
| Security | **PASS** | Kodda secret 0; `.env` gitignore; `.env.example` boş; şifre yalnız env → `ofisvio:bootstrap-accounts` (politika zorunlu, test müşterisi production'da reddedilir) |
| Build | **PASS** | `npm run build` |
| Tests | **PASS** | pint ✅ · phpstan level 6 0 hata ✅ · **226/226** ✅ |

**PRODUCTION READY?** Kod tarafı için evet *bu kapsamda*; ancak §0'daki UNKNOWN'lar
(üretim DB'sinde koşu, e-posta sağlayıcısı, clamd, gerçek sunucu) `ofisvio:doctor`
ile canlıda doğrulanmadan **"production ready" denmez**.

## B. Bulgu tablosu

| ID | Sev. | Modül | Problem | Kanıt | Kök neden | Düzeltme | Test |
|---|---|---|---|---|---|---|---|
| F-01 | P1 | CMS/vitrin | Fiyatlar, çözümler, odalar, planlar, footer, marka telefon/e-posta `config/ofisvio.php`'de sabit; DB kaydı yoksa vitrin config'i basıyordu | `grep ₺ config/ofisvio.php` → 4 fiyat satırı; `Website::brand()` config fallback | Faz 10'da bloklar "config varsayılanı" ile devralınmıştı | `database/seeders/data/site_blocks.json` + `SiteBlockSeeder` (firstOrCreate); config'te yalnız ürün adı; kayıt yoksa bölüm basılmaz; talep formu çözüm listesi bloklardan | `MockDataDetectionTest::config_dizininde_ticari_veri_yok`, `SiteBlocksTest` |
| F-02 | P1 | CRM | Vitrin formu `leads` tablosuna yazıyor ama **hiçbir ekran okumuyordu**; `lead.view/assign` izinleri kullanılmıyordu | `grep Lead routes/panel.php` → 0 | Modül yarım kalmış | `/panel/talepler`: liste (süzgeç/arama/sayfalama), detay, atama + durum + iç not; `assigned_to/handled_at/internal_note` kolonları | `LeadAdminTest` |
| F-03 | P1 | Hesap açılışı | Şifre ilk kurulumda interaktif; env tabanlı, politikalı açılış yoktu; test müşterisi mekanizması yoktu | `MakeAdminCommand` prompt | — | `ofisvio:bootstrap-accounts` (config('ofisvio.accounts') ← env; ≥16 kr. politika; test müşterisi production'da reddedilir); `.env.example` boş anahtarlar | `ErrorHandlingTest::env_tabanli_hesap_acilisi...` |
| F-04 | P2 | İçerik | DELETE yoktu; liste arama/sayfalama yoktu (`get()` tümü) | `route:list` içerik DELETE 0 | — | Soft delete (`content.archive`, yalnız taslak/arşiv, alt sayfası olan silinmez); `q` + `paginate(30)`; kullanıcı listesi `q` + `paginate(50)` | mevcut testler + phpstan |
| F-05 | P2 | Hata sayfaları | 403/404/419/429/500/503 için özel görünüm yoktu (Laravel varsayılan İngilizce) | `ls resources/views/errors` boş | — | Türkçe hata sayfaları, ayrıntı sızdırmaz | `ErrorHandlingTest` |
| F-06 | P2 | Vitrin | "Aynı gün — belge inceleme süresi" istatistiği kodda sabit ticari iddia | `HomeController::stats` | — | Panel metni (`stats_review_time`), boşsa basılmaz | `SiteBlocksTest` (texts) |
| F-07 | P3 | Ölü UI | `resources/views/welcome.blade.php` (Laravel varsayılanı; laravel.com/laracasts bağlantıları) | dosya var, rota yok | Kurulum artığı | Silindi | `MockDataDetectionTest` |
| F-08 | P3 | Panel | Menü düz listeydi | — | — | Gruplandı: Müşteri · İçerik · Site · CRM · Sistem · Hesap (`panel/partials/nav.blade.php`) | render testleri |
| F-09 | P3 | Kod | 3 `TODO` yorumu (fatura/sözleşme/adres modülleri yokken kapatma engelleri) | `CompanyActivationService::blockersForClosure` | Modüller yok | Açık dille yeniden yazıldı; davranış aynı (bu kontroller yapılmıyor — §0) | — |

## C. Mock raporu

| Dosya | Satır | Tür | Risk | Aksiyon |
|---|---|---|---|---|
| `config/ofisvio.php` | (eski) 60–140 | Sabit ticari liste (fiyatlı) | Yüksek — üretimde DB yerine config basılırdı | DB'ye taşındı (F-01) |
| `database/seeders/LocationSeeder.php` | — | 14 şube referans verisi (adres, fiyat metni) | Orta — gerçek şube listesi olduğu doğrulanamadı → **UNKNOWN** | DB'de yaşar, panelden düzenlenir/gizlenir (`geo.edit`, `geo.publish`); yeni şube ekleme/silme ekranı **yok** (bkz. §E) |
| `database/seeders/WebsiteSeeder.php` | — | 3 yazı + 3 yasal sayfa **taslağı** (gövdesiz) | Düşük — yayında değil, vitrine çıkmaz | Korundu (referans iskelet) |
| `resources/views/site/partials/*.blade.php` | "kapak · 800×500", "lokasyon ana görseli" | Görsel yer tutucu etiketi — yalnız görsel atanmamışsa | Düşük | Medya kütüphanesi eklendi; kapak/hero atanınca gerçek görsel basılır |
| `tests/**`, `database/factories/**` | — | Test fixture | — | ALLOWED |

## D. LocalStorage raporu

| Dosya | Satır | Anahtar | Veri | İzinli? | Aksiyon |
|---|---|---|---|---|---|
| — | — | — | — | — | **Kullanım yok** (0 eşleşme; `MockDataDetectionTest::tarayici_depolamasi_kullanilmiyor` ile kilitli) |

## E. Admin CRUD raporu

| Modül | List | Create | Read | Update | Delete | Search/Filter/Paginate | DB persist | Frontend sync |
|---|---|---|---|---|---|---|---|---|
| Sayfalar / Yazılar | ✅ | ✅ | ✅ | ✅ (taslak; yayındaki için çalışma taslağı) | ✅ soft (yeni) | ✅ tür/durum/arama/sayfalama (yeni) | ✅ | ✅ önbellek sürüm atlar; testli |
| Çalışma taslağı | — | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ birleşince |
| Ana sayfa (metin + bloklar) | ✅ | — | ✅ | ✅ | ✅ (boş = kaldır) | — | ✅ `site_blocks` | ✅ testli |
| Menü / tema / bağlantılar / site ayarları | ✅ | — | ✅ | ✅ | — | — | ✅ | ✅ testli |
| Websiteler | ✅ | ✅ | ✅ | ✅ | ✅ soft (içeriksiz, varsayılan dışı) | ❌ sayfalama yok (site sayısı küçük) | ✅ | ✅ |
| SEO (site ayarı + denetim) | ✅ | — | ✅ | ✅ (JIT) | — | — | ✅ | ✅ |
| GEO / lokasyonlar | ✅ | ✅ (`geo.edit`) | ✅ | ✅ künye + varlık + yayın | ✅ (`geo.publish`, gizliyken) | — | ✅ | ✅ |
| Önbellek | ✅ | — | ✅ (anahtar özeti) | ✅ ayarlar (JIT) | ✅ geçersiz kıl (JIT) | — | ✅ | ✅ |
| Talepler (CRM) | ✅ (yeni) | ✅ (vitrin formu) | ✅ | ✅ atama/durum/not | — (KVKK kanıtı; silme bilinçli yok) | ✅ tür/durum/arama/sayfalama | ✅ | ✅ |
| Kullanıcılar (personel) | ✅ | ✅ davet | ✅ | ✅ rol | askıya alma (silme bilinçli yok) | ✅ arama/sayfalama (yeni) | ✅ | — |
| Organizasyon | — | ✅ açılış | ✅ (context) | ✅ ad (`organization.manage`) | ❌ (bilinçli) | — | ✅ | — |
| Şirketler | ✅ | ✅ | ✅ | ✅ unvan/vergi no (`company.update`) | ❌ (fesih durum makinesi var) | ❌ | ✅ | — |
| KYC | ✅ kuyruk | ✅ yükleme | ✅ indirme (JIT) | ✅ karar | — | ✅ | ✅ private disk + karantina | — |
| Üyeler | ✅ | ✅ davet | ✅ | askıya al/etkinleştir | — | — | ✅ | — |

**Denetim sonrası kapatılanlar (17 Eylül):** lokasyon ekleme/künye/silme; şirket
künyesi; organizasyon adı; website silme; medya kütüphanesi (kapak + hero,
KYC ile aynı karantina zinciri) — hepsi testli (bkz. ROADMAP faz 30).

## F. Frontend ↔ backend sözleşme raporu

| Kontrol | Beklenen | Gerçek | Durum |
|---|---|---|---|
| Görünümde `route('x')` → tanımlı rota | 0 tanımsız | 0 (`ApiRouteConsistencyTest`) | CONNECTED |
| Form `action` | `route()`/değişken | 0 sabit yol | CONNECTED |
| Panel rotası → `auth` + `permission:`/`tenant` | hepsi | 122 rotanın tamamı (`panel/hesap*`, `panel/organizasyon`, `panel` muaf: auth+2FA) | CONNECTED |
| Form alanı ↔ FormRequest | birebir | `StoreContentRequest`, `StoreLeadRequest`, `UpdateNavigationRequest`, `StoreWebsiteRequest`, KYC/üye/JIT request'leri; testlerde 422 alan adıyla doğrulanıyor | CONNECTED |
| Backend'de olup UI'dan çağrılmayan rota | 0 | `content:publish-scheduled` (cron), `ofisvio:*` komutlar (CLI) dışında 0 | — |
| UI'da olup backend'de olmayan | 0 | 0 | — |

## G. Dış bağlantılar

| Adres | Sınıf |
|---|---|
| fonts.googleapis.com / fonts.gstatic.com | Allowed asset (yazı tipi) |
| openstreetmap.org/export/embed (lokasyon sayfası) | Allowed asset — **yalnız ziyaretçi tıklayınca** yüklenir (`data-map-load`) |
| google.com/maps?q= (bağlantı) | Allowed (dış bağlantı, veri gitmez) |
| Panel yer tutucu örnekleri (`placeholder="https://calendly.com/..."`) | UI ipucu metni, istek yok |
| fetch/axios/XHR/WebSocket | **0** |

## H. Yeniden tarama (düzeltme sonrası)

```
localStorage/sessionStorage/indexedDB/cookie : 0
mock/dummy/fake/demo veri yapısı (runtime)    : 0
config'te ₺ / telefon / e-posta               : 0
fetch/axios/XHR/WebSocket (JS)                : 0
fallback → mock                               : 0
setTimeout → success / toast                  : 0
TODO/FIXME (app)                              : 0
kodda secret                                  : 0
pint ✅  phpstan(6) 0 hata ✅  test 226/226 ✅  build ✅
```

## I. Kabul kriterleri

| Kriter | Durum |
|---|---|
| Production mock/demo/fake veri | 0 ✅ |
| localStorage'da iş/hassas veri | 0 ✅ |
| Sahte API cevabı / sahte başarı | 0 ✅ |
| Sabit ticari veri | 0 (config) ✅ — seed verisi DB'de, panelden yönetilir |
| Sözleşmeler tutarlı | ✅ (Blade düzeyi; TS N/A) |
| Kritik CRUD DB'ye yazar / admin değişikliği vitrine yansır | ✅ testli |
| Auth / RBAC / tenant izolasyonu | ✅ testli (18'lik matris) |
| Doğrulama tutarlı / hata yönetimi | ✅ (422 alan hataları, 403/404 Türkçe sayfa) |
| Önbellek izolasyonu | ✅ |
| Doğrudan sağlayıcı çağrısı / açıkta secret | 0 ✅ |
| Pint / PHPStan / Test / Build | ✅ |
| **Üretim ortamında doğrulama** (MySQL, clamd, mail, sunucu) | **UNKNOWN** — `php artisan ofisvio:doctor` canlıda 0 dönmeden "production ready" denmez |
