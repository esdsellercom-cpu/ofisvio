<?php

/**
 * Köprü giriş dosyası — belge kökü uygulama klasörünü gösterdiğinde (Plesk/cPanel gibi kökü değiştirilemeyen
 * ortamlar) ana sayfa isteğini asıl giriş dosyasına devreder.
 *
 * Neden gerekli: kök yolu ("/") için yeniden yazma kuralı bazı nginx+Apache zincirlerinde hiç çalışmıyor;
 * sunucu dizin indeksini arıyor ve bulamayınca hata veriyor. Burada gerçek bir index.php olduğunda bu sorun
 * sunucu yapılandırmasından bağımsız olarak ortadan kalkar.
 *
 * Belge kökü doğrudan .../public ise bu dosya web'e hiç açılmaz — zararsızdır.
 */
require __DIR__.'/public/index.php';
