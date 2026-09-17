# OFISVIO — Frontend ↔ Admin Kapsama Matrisi (master prompt §2 / §55)

Tarih: 17 Eylül 2026 · Commit: bkz. `git log` · Kapı: pint ✅ · phpstan 0 ✅ · testler ✅ · build ✅

Durum sözlüğü: **PASS** tam zincir · **PARTIAL** eksik halka (açıklamalı) · **N/A** modül yok (bilinçli) ·
**UNKNOWN** doğrulanamadı. Sütunlar: DB = tablo/kolon · Backend = servis/eylem · Admin = panel ekranı ·
Workflow = durum makinesi/akış · Bildirim = Bildirim Merkezi olayı · Cache = ContentCache/ayar önbelleği
geçersizlemesi · Audit = `audit_logs` ya da özel denetim tablosu · Test = `php artisan test`.

## A. Vitrin (frontend) özellikleri

| Frontend özelliği | DB | Backend | Admin | Workflow | Bildirim | Cache | Audit | Test | Durum |
|---|---|---|---|---|---|---|---|---|---|
| Üst şerit (metin, telefon, WhatsApp, CTA) | `websites.*`, `site_blocks.texts` | `WebsiteService`, `SiteBlockService` | Websiteler › ayarlar; Metinler & bloklar | yayın anında | — | site sürümü | `settings.*`/blok | SiteBlocksTest | PASS |
| **Duyuru şeridi** (metin, bağlantı, bitiş) | `websites.announcement_*` | `WebsiteService::updateSettings` | Websiteler › ayarlar | zamanlı (bitiş) | — | site sürümü | — (website güncelleme) | SiteBlocksTest | PASS |
| Üst menü (etiketler + hedefler) | `site_blocks.texts` + `site_revisions` | `SiteLayoutComposer` (yayınlanmış bölüm çapaları) | Metinler; Ana sayfa tasarımı | yayın | — | site sürümü | `site.published` | SiteBuilderTest | PASS |
| Header CTA (Teklif Al) | `texts.cta_header` | — | Metinler | — | — | site sürümü | blok | SiteBlocksTest | PASS |
| Hero (etiket, başlık, açıklama, süzgeç, CTA, görsel) | `texts.*`, `websites.hero_media_id`, `site_sections` | `SiteBuilderService`, `MediaService` | Ana sayfa tasarımı (bölüm ayarı), Medya | taslak→yayın | — | site sürümü | `site.section_updated` | SiteBuilderTest, MediaLibraryTest | PASS |
| İstatistikler (lokasyon/şehir/bölge) | `locations` (canlı sayım) | `HomeController::stats` | GEO & lokasyonlar | yayın (geo.publish) | — | — | — | SiteTest | PASS |
| Çözüm kartları | `site_blocks.solutions` | `SiteBlockService` | Metinler & bloklar | anında | — | site sürümü | blok | SiteBlocksTest | PASS |
| Nasıl çalışır (adımlar) | `companies.status` makinesi (`ActivationJourney`) | `CompanyActivationService` | Şirket detayı | durum makinesi | — | — | `company_status_transitions` | CompanyTest | PASS |
| Lokasyon kartları + bölge sekmeleri + harita | `locations` | `GeoService` | GEO & lokasyonlar | geo.publish | — | site sürümü | `room.*`/geo | GeoEngineTest | PASS |
| **Toplantı & etkinlik (oda listesi, fiyat, kapasite)** | `rooms` | `BookingService::bookableRooms` | GEO › lokasyon › Odalar | oda aktif/pasif | — | site sürümü (oda değişince) | `room.created/updated/deleted` | BookingTest | PASS |
| **"Aynı gün teyit" rozeti** | `settings.booking.confirmation_sla/auto_confirm` | `BookingService::confirmationBadge` | Ayarlar › Rezervasyon | — | — | ayar önbelleği | `settings.changed` | BookingTest, SettingsNotificationTest | PASS |
| **Rezervasyon aracı → /rezervasyon akışı** | `bookings`, `booking_status_history` | `BookingService::book` (uygunluk motoru + kilit) | Rezervasyonlar (sekmeler, detay, takvim) | REQUESTED→PENDING_APPROVAL/CONFIRMED→… | `booking.requested/confirmed/rejected/cancelled/expired` (WhatsApp/e-posta/uygulama içi) | — (canlı) | `booking.created/status_changed/...` | BookingTest (§57 uçtan uca) | PASS |
| Dahil olanlar | `site_blocks.amenities` | `SiteBlockService` | Metinler & bloklar | anında | — | site sürümü | blok | SiteBlocksTest | PASS |
| Üyelikler tablosu + not | `site_blocks.plans/plan_rows/pricing_note` | `SiteBlockService` | Metinler & bloklar | anında | — | site sürümü | blok | SiteBlocksTest | PASS |
| Yazılar bölümü | `contents` (post) | `ContentService` | İçerik › Yazılar (akış, takvim) | DRAFT→REVIEW→…→PUBLISHED | — | site sürümü | `content_revisions` | ContentFlowTest | PASS |
| Teklif formu (başlık, vaatler, alanlar, KVKK) | `leads`, `texts.lead_*`, `site_blocks.solutions` | `LeadService::capture` | CRM › Talepler; Metinler | lead status | `lead.created` | — | — (lead güncelleme panelde) | LeadAdminTest, SiteTest | PASS |
| **Serbest metin / SSS / CTA şeridi bölümleri** | `site_sections.settings` | `SiteBuilderService` | Ana sayfa tasarımı | taslak→yayın | — | site sürümü | `site.section_*` | SiteBuilderTest | PASS |
| Footer (marka, iletişim, saatler, WhatsApp, sütunlar, yasal sayfalar) | `websites.*`, `site_blocks.footer_columns`, `contents` (page) | composer'lar | Websiteler › ayarlar; Metinler & bloklar; Sayfalar | — | — | site sürümü | blok/website | SiteBlocksTest | PASS |
| Yüzen WhatsApp düğmesi | `websites.whatsapp_number` + `texts.whatsapp_message` | `Website::brand` | Websiteler › ayarlar | — | — | site sürümü | — | SiteBlocksTest | PASS |
| KVKK bağlantısı | `contents` (aydınlatma sayfası) | `SiteLayoutComposer` | İçerik › Sayfalar | yayın akışı | — | site sürümü | `content_revisions` | SiteBlocksTest | PASS |
| Sayfalar / alt sayfalar / blog / kategori / etiket | `contents`, `content_drafts` | `ContentService` | İçerik | yayın akışı + zamanlama | — | site sürümü | revizyon | ContentFlowTest, ContentEngineTest | PASS |
| SEO (meta, canonical, robots, OG, JSON-LD, sitemap, hreflang) | `websites.seo_*`, `contents.meta_*` | `SeoService` | SEO; Site SEO (müşteri) | JIT ayar | — | site sürümü | JIT grant | SeoEngineTest | PASS (hreflang: tek dil, N/A) |
| Müşteri siteleri (tenant) | `websites`, `contents` | `WebsiteService`, `SiteController` | Şirket › Site | yayın akışı | — | site sürümü | revizyon | TenantSiteTest | PASS |
| Popup / modal / slider / kampanya | — | — | — | — | — | — | — | — | N/A (vitrinde yok; bilinçli — CTA şeridi + duyuru şeridi ile karşılanır) |
| Çok dillilik (TR/EN/DE) | — | — | — | — | — | — | — | — | PARTIAL: tek dil (TR); locale altyapısı yok — ürün kararı bekliyor |

## B. Admin modülleri → gerçek süreç

| Admin modülü | Bağlı süreç | Durum |
|---|---|---|
| Şirketler / KYC / Üyeler / Aktivasyon | müşteri paneli + durum makinesi + JIT | PASS |
| **Rezervasyonlar** (liste, sekmeler, detay, masa, takvim, JIT override) | vitrin talebi + müşteri paneli + bildirimler | PASS |
| **Odalar** | vitrin oda listesi + rezervasyon akışı | PASS |
| **Ayarlar** (rezervasyon politikası, bildirim, WhatsApp, genel; lokasyon üzerine yazma) | uygunluk motoru, rozet, kuyruk denemesi | PASS |
| **Bildirim merkezi** (kurallar, alıcılar, şablonlar, günlük) + gelen kutusu | booking/lead/kyc olayları → WhatsApp/e-posta/SMS/uygulama içi | PASS (SMS/WhatsApp sağlayıcı kimlik bilgisi: UNKNOWN — env ile açılır) |
| **Ana sayfa tasarımı** (bölümler, revizyon, önizleme) | vitrin ana sayfası | PASS |
| Metinler & bloklar | vitrin bölümleri | PASS |
| Websiteler (ayarlar, tema, menü, hero, duyuru, WhatsApp, saatler, önbellek) | vitrin/tenant siteleri | PASS |
| İçerik (sayfa/yazı, taslak, takvim, medya, iç bağlantı) | vitrin CMS | PASS |
| SEO / GEO | vitrin head + sitemap + lokasyon sayfaları | PASS |
| CRM › Talepler | teklif formu | PASS (eski "ön rezervasyon" kayıtları salt okunur) |
| Denetim kaydı (genel + JIT + giriş + geçiş + webhook + entegrasyon) | tüm kritik işlemler | PASS |
| Performans / Önbellek / Kullanıcılar (lokasyon kapsamlı roller) | sistem | PASS |
| Finans / Fatura / Ödeme / Abonelik / Kargo / Posta | — | N/A (modül yok; faz 19+, entegrasyon kimlik bilgisi gerekir) |

## C. §57 uçtan uca senaryo — doğrulama

`BookingTest::vitrin_uctan_uca_talep_whatsapp_onay_musteri_bildirimi_ve_denetim`: site → lokasyon → gerçek
odalar → canlı slotlar → form (KVKK, E.164) → sunucu doğrulama → uygunluk motoru → işlem + kilit → talep
(PENDING_APPROVAL) → commit → `BookingStatusChanged` → Bildirim Merkezi → WhatsApp sağlayıcısı (Gateway,
HTTP sahteleme ile gerçek adaptör; `+903326060999` alıcısı **DB kaydı**) + uygulama içi + e-posta →
yönetici "Onay bekleyen" → detay → Onayla → CONFIRMED → takvim dolu → müşteri e-postası → denetim izi
(`booking.created`, `booking.status_changed`×2) → check-in → tamamlandı. Sağlayıcı arızası
(`saglayici_arizasi_talebi_bozmaz_...`): talep kaydedilir, günlük `failed`, süper yöneticiye uyarı.

## D. Sıfır tolerans (§56) — tarama sonuçları

| Kural | Sonuç | Kanıt |
|---|---|---|
| 0 mock/demo ticari veri | ✅ | `MockDataDetectionTest::uygulama_kodunda_mock_demo_veri_yapisi_yok` |
| 0 sabit ticari veri (telefon, WhatsApp, fiyat) | ✅ | `MockDataDetectionTest::kaynak_kodda_sabit_telefon_whatsapp_ve_fiyat_yok`, `config_dizininde_ticari_veri_yok` |
| 0 localStorage/sessionStorage iş verisi | ✅ | `tarayici_depolamasi_kullanilmiyor` |
| 0 yönetilemeyen vitrin özelliği | ✅ (A tablosu) | — |
| 0 ölü admin menüsü | ✅ | `ApiRouteConsistencyTest` (görünümdeki route adları tanımlı; panel rotaları izin/tenant zincirli) |
| 0 sahte dashboard istatistiği | ✅ | `BookingService::dashboard` gerçek toplamlar; vitrin istatistikleri canlı sayım |
| 0 doğrudan dış sağlayıcı çağrısı | ✅ | `backend_dis_saglayiciya_yalniz_gateway_uzerinden_cikar`, `frontend_dis_saglayiciya_dogrudan_baglanmiyor` |
| 0 DB'siz / admin görünürlüğü olmayan / audit'siz rezervasyon | ✅ | BookingTest |
| 0 tenant sızıntısı | ✅ | `TenantIsolationTest`, `BookingTest::tenant_siniri_*` |

## E. Bilinçli sınırlar / açık kalemler

- **Ödeme** (§6 price/tax/payment_status): tutar KDV hariç tam sayı TL; ödeme modülü yok (faz 19+, iyzico kimlik bilgisi).
- **Çok dillilik** (§37): tek dil. **A/B deneyi**, **video hero**, **logo bulutu**, **galeri** bölüm tipleri yazılmadı (kütüphaneye eklenebilir).
- **Otomasyon / Workflow motoru** (§43 Automation): durum makineleri kodda; görsel workflow düzenleyici yok.
- **WhatsApp/SMS sağlayıcı**: kod yolu gerçek (Meta Cloud API / HTTP SMS); canlı kimlik bilgisi ile üretim doğrulaması **UNKNOWN**.
- **Sürükle-bırak**: bölüm sıralaması HTML5 DnD (bağımlılıksız); takvimde sürükleyerek yeniden planlama yok (form ile).
