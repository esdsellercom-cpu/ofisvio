<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * APP_KEY rotasyonu (audit F-14). Akış: APP_PREVIOUS_KEYS=eski, APP_KEY=yeni → deploy (eski anahtarla şifreli
 * kayıtlar Laravel'in previous_keys zinciriyle çözülmeye devam eder) → `ofisvio:reencrypt` (her kayıt yeni anahtarla
 * yeniden şifrelenir) → doctor "Anahtar rotasyonu" ok → APP_PREVIOUS_KEYS kaldırılır. Eski anahtar, bekleyen kayıt
 * sıfırlanmadan kaldırılırsa veri çözülemez — doctor bunu üretimde hata sayar.
 * Şifreli alanlar tek yerde listelenir (yeni alan eklerken buraya satır ekleyin).
 */
class KeyRotationService
{
    /** @var array<int, array{table: string, column: string}> */
    public const FIELDS = [
        ['table' => 'integration_secrets', 'column' => 'value'],
        ['table' => 'webhook_endpoints', 'column' => 'secret'],
        ['table' => 'webhook_deliveries', 'column' => 'body'],
        ['table' => 'member_profiles', 'column' => 'identity_number'],
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** Yalnız MEVCUT anahtarla çözülemeyen (eski anahtarla şifreli) kayıt sayısı: tablo => adet. @return array<string, int> */
    public function pending(): array
    {
        $current = $this->currentOnly();
        $out = [];

        foreach (self::FIELDS as $field) {
            if (! Schema::hasTable($field['table'])) {
                continue;
            }

            $n = 0;

            foreach (DB::table($field['table'])->whereNotNull($field['column'])->where($field['column'], '!=', '')->select(['id', $field['column']])->cursor() as $row) {
                if (! $this->decryptable($current, (string) $row->{$field['column']})) {
                    $n++;
                }
            }

            $out[$field['table'].'.'.$field['column']] = $n;
        }

        return $out;
    }

    /** Eski anahtarla şifreli her kaydı çözer (previous_keys zinciri) ve yeni anahtarla yazar. @return array<string, int> */
    public function reencrypt(bool $dryRun = false): array
    {
        $current = $this->currentOnly();
        $out = [];

        foreach (self::FIELDS as $field) {
            if (! Schema::hasTable($field['table'])) {
                continue;
            }

            $n = 0;

            foreach (DB::table($field['table'])->whereNotNull($field['column'])->where($field['column'], '!=', '')->select(['id', $field['column']])->cursor() as $row) {
                $cipher = (string) $row->{$field['column']};

                if ($this->decryptable($current, $cipher)) {
                    continue;
                }

                $plain = Crypt::decryptString($cipher); // previous_keys ile; çözülemezse DecryptException — sessiz geçilmez

                if (! $dryRun) {
                    DB::table($field['table'])->where('id', $row->id)->update([$field['column'] => Crypt::encryptString($plain)]);
                }

                $n++;
            }

            $out[$field['table'].'.'.$field['column']] = $n;
        }

        if (! $dryRun && array_sum($out) > 0) {
            $this->audit->record(null, 'security.key_rotated', 'system', null, [], $out);
        }

        return $out;
    }

    private function currentOnly(): Encrypter
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return new Encrypter($key, (string) config('app.cipher'));
    }

    private function decryptable(Encrypter $encrypter, string $cipher): bool
    {
        try {
            $encrypter->decryptString($cipher);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
