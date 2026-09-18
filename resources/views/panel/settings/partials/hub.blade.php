{{-- Ayar merkezi haritası (faz 61a): global yapılar tek yerden; her kart var olan ekrana gider (ayrı sistem kurulmaz). --}}
@php($cards = [
    ['Genel', 'Saat dilimi, para birimi, yerelleştirme', route('panel.settings.index', ['grup' => 'general']), true],
    ['Header', 'Logo, menü, mega menü, CTA, sticky/şeffaf, renkler — tüm sitede', route('panel.settings.chrome.header'), true],
    ['Footer', 'Logo, açıklama, kolonlar, sosyal, bülten, yasal bağlantılar, copyright', route('panel.settings.chrome.footer'), true],
    ['SEO', 'Command Center, gelişmiş ayarlar, sitemap, canonical, şema', route('panel.seo.center.home'), true],
    ['GEO', 'Marka tanımı, llms.txt, cevap motoru, Knowledge Graph', route('panel.seo.geo.home'), true],
    ['Yasal metinler', 'KVKK, gizlilik, çerez, kullanım koşulları — CMS sayfaları', route('panel.content.index', ['kind' => 'page']), true],
    ['Bildirimler', 'Kurallar, alıcılar, kanallar, otomasyon', route('panel.notifications.index'), true],
    ['API & Entegrasyonlar', 'Entegrasyon merkezi: bağlan → test et → aktifleştir', route('panel.settings.api'), true],
    ['Webhooks', 'Giden webhook uçları, olaylar, teslim logları', route('panel.settings.api'), true],
    ['Güvenlik', '2FA zorunluluğu, oturum, şifre politikası', route('panel.settings.index', ['grup' => 'security']), true],
    ['Medya', 'Medya kütüphanesi, otomatik boyutlandırma', route('panel.content.media.index'), true],
    ['E-posta', 'SMTP / sağlayıcı bağlantısı ve test', route('panel.settings.api').'#mail', true],
    ['Çerezler', 'Çerez politikası sayfası ve footer bağlantısı', route('panel.settings.chrome.footer'), true],
    ['Sistem', 'Sağlık (doctor), performans, önbellek', route('panel.settings.health'), true],
])
<div class="grid-auto" style="--min:200px;--gap:10px;margin-bottom:22px">
    @foreach ($cards as [$title, $desc, $href])
        <a href="{{ $href }}" class="kpi" style="border:1px solid var(--line);border-radius:var(--r);text-decoration:none"><span class="k">{{ $title }}</span><span class="d">{{ $desc }}</span></a>
    @endforeach
</div>
