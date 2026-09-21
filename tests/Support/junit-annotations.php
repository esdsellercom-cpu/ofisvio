<?php

/**
 * CI yardımcısı (audit F-02): JUnit XML'deki her hata/başarısızlık GitHub Actions "::error" ek açıklamasına dönüşür —
 * kalite kapısı kırmızıyken hangi testin, hangi mesajla düştüğü loga girmeden (oturum gerekmeden) görünür.
 * Kullanım: php tests/Support/junit-annotations.php storage/logs/junit.xml
 */
$path = $argv[1] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "junit dosyası yok: {$path}\n");
    exit(0);
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "junit XML okunamadı\n");
    exit(0);
}

$n = 0;

foreach ($xml->xpath('//testcase') as $case) {
    foreach (['failure', 'error'] as $kind) {
        foreach ($case->{$kind} as $problem) {
            $message = trim(preg_replace('/\s+/', ' ', (string) $problem));
            $title = (string) $case['class'].'::'.(string) $case['name'];
            printf("::error title=%s::%s\n", str_replace([':', ','], ['%3A', '%2C'], $title), mb_substr(str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $message), 0, 700));
            $n++;
        }
    }
}

fwrite(STDERR, $n." test hatası ek açıklamaya yazıldı\n");
