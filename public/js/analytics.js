// Analitik yükleyici (faz 44 + audit F-11/HTTP cache): GA4 / GTM bootstrap kodu satır içi değil bu dış dosyadadır —
// böylece herkese açık sayfalarda istek başına değişen nonce HTML'e girmez (ETag/304 önbelleği bozulmaz) ve CSP
// 'self' ile yüklenir. Kimlikler bu <script> etiketinin data-ga4 / data-gtm özniteliğinden okunur; rıza yoksa etiket
// hiç basılmaz (CookieConsent). HTTP çağrısı yapılmaz; yalnız Google'ın kendi script'i eklenir.
(function () {
  var me = document.currentScript;
  if (!me) return;
  var gtm = me.getAttribute('data-gtm');
  var ga4 = me.getAttribute('data-ga4');
  if (gtm) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    var f = document.getElementsByTagName('script')[0];
    var j = document.createElement('script');
    j.async = true;
    j.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtm);
    f.parentNode.insertBefore(j, f);
    return;
  }
  if (ga4) {
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', ga4);
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga4);
    document.head.appendChild(s);
  }
})();
