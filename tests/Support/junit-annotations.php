<?php

/**
 * CI yardımcısı (audit F-02): JUnit XML'deki her hata/başarısızlık GitHub Actions "::error" ek açıklamasına dönüşür —
 * kalite kapısı kırmızıyken hangi testin, hangi mesajla düştüğü loga girmeden (oturum gerekmeden) görünür.
 * Doctor tablosu gibi uzun çıktılarda yalnız anlamlı satırlar (✗ satırları, özet, "Failed asserting") taşınır.
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
            $raw = (string) $problem;

            if (str_contains($raw, '| ✗ |') || str_contains($raw, 'Failed asserting')) {
                $keep = [];

                foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    if (str_contains($line, '| ✗ |') || str_contains($line, '| ! |') || str_contains($line, 'Yedek') || str_contains($line, 'kontrol ·') || str_contains($line, 'Failed asserting') || str_contains($line, 'does not contain') || str_starts_with($line, 'Tests\\') || str_starts_with($line, '-') || str_starts_with($line, '+')) {
                        $keep[] = $line;
                    }
                }

                if ($keep !== []) {
                    $raw = implode(' || ', array_slice($keep, 0, 20));
                }
            }

            $message = trim((string) preg_replace('/\s+/', ' ', $raw));
            $title = (string) $case['class'].'::'.(string) $case['name'];
            printf("::error title=%s::%s\n", str_replace([':', ','], ['%3A', '%2C'], $title), mb_substr(str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $message), 0, 1500));
            $n++;
        }
    }
}

fwrite(STDERR, $n." test hatası ek açıklamaya yazıldı\n");
