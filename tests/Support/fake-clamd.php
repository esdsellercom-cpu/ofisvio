<?php

/**
 * Sahte clamd — ClamAvScanner'ın INSTREAM protokolünü gerçek bir soket
 * üzerinden sınamak için (tests/Unit/ClamAvScannerTest). Tek bağlantı kabul
 * eder, akışı okur, sahte zararlı işareti varsa FOUND, yoksa OK döner.
 *
 * Kullanım: php fake-clamd.php <port> [mode]
 *   mode: normal (varsayılan) | error (ERROR yanıtı) | silent (yanıtsız kapat)
 */
$port = (int) ($argv[1] ?? 0);
$mode = $argv[2] ?? 'normal';

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

// Test sürecine "hazırım" sinyali: gerçek portu yaz.
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr((string) $name, strrpos((string) $name, ':') + 1)."\n");
fflush(STDOUT);

$conn = stream_socket_accept($server, 10);

if ($conn === false) {
    exit(2);
}

$command = '';
while (($c = fread($conn, 1)) !== '' && $c !== false) {
    if ($c === "\0") {
        break;
    }
    $command .= $c;
}

$payload = '';
if ($command === 'zINSTREAM') {
    while (true) {
        $header = stream_get_contents($conn, 4);
        if ($header === false || strlen($header) < 4) {
            break;
        }
        $len = unpack('N', $header)[1];
        if ($len === 0) {
            break;
        }
        $payload .= stream_get_contents($conn, $len);
    }
}

// Gerçek EICAR dizisi KULLANILMAZ: geliştirici makinesindeki antivirüs geçici
// dosyayı anında siler (Windows Defender bunu yaptı) ve test dosya okunamadan
// biter. Sahte motor kendi zararsız işaretini arar.
$marker = 'OFISVIO-FAKE-MALWARE-MARKER';

$response = match ($mode) {
    'error' => "INSTREAM size limit exceeded. ERROR\0",
    'silent' => '',
    default => str_contains($payload, $marker) ? "stream: Win.Test.EICAR_HDB-1 FOUND\0" : "stream: OK\0",
};

fwrite($conn, $response);
fclose($conn);
fclose($server);
