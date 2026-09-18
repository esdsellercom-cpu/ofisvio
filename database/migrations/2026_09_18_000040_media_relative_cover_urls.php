<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Medya adresleri host'a göre bağıl oldu (Media::url → `/storage/…`): kayıtlı içerik kapak adreslerinde APP_URL ile
 * yazılmış mutlak `/storage/` adresleri bağıl hale getirilir — vitrin hangi alan adından açılırsa açılsın kapak kırılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contents')->whereNotNull('cover_url')->where('cover_url', 'like', 'http%')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $relative = preg_replace('~^https?://[^/]+(/storage/)~', '$1', (string) $row->cover_url);

                if (is_string($relative) && $relative !== $row->cover_url) {
                    DB::table('contents')->where('id', $row->id)->update(['cover_url' => $relative]);
                }
            }
        });
    }

    public function down(): void
    {
        // Bağıl adres her ortamda geçerlidir; geri dönüşte mutlak adres üretmek gerekmez.
    }
};
