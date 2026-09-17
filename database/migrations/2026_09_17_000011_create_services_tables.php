<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Hizmet modülü (faz 4): hizmetler gerçek varlık (`services`), lokasyon ↔ hizmet ilişkisel
 * (`location_service`). Önceki iki kaynak taşınır ve kaldırılır:
 *   - site_blocks.solutions (JSON blok) → services satırları
 *   - locations.tags (serbest metin dizisi) → location_service (ada göre eşleşen hizmet;
 *     eşleşmeyen etiket için hizmet oluşturulur — veri kaybı yok)
 * Vitrin kartları, süzgeç, teklif formu seçenekleri ve JSON-LD Service düğümleri artık
 * yalnız bu tablodan gelir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 90)->unique();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable(); // Markdown, hizmet sayfası
            $table->string('price_text', 60)->nullable(); // "₺790/ay'dan" — panelden, kodda yok
            $table->string('booking_kind', 20)->nullable(); // meeting|event|focus → rezervasyon akışına bağlı hizmet
            $table->boolean('is_flagship')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('location_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'service_id']);
        });

        $this->migrateData();

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('badge');
        });
        Schema::dropIfExists('location_service');
        Schema::dropIfExists('services');
    }

    /** Mevcut blok ve etiketleri hizmetlere taşır (idempotent: ada göre). */
    private function migrateData(): void
    {
        $now = now();
        $ids = [];
        $order = 0;

        $ensure = function (string $name, array $extra = []) use (&$ids, &$order, $now): int {
            $key = mb_strtolower($name);

            if (isset($ids[$key])) {
                return $ids[$key];
            }

            $slug = Str::slug($name);
            $existing = DB::table('services')->where('slug', $slug)->value('id');

            if ($existing !== null) {
                return $ids[$key] = (int) $existing;
            }

            $ids[$key] = (int) DB::table('services')->insertGetId(array_merge([
                'name' => $name, 'slug' => $slug, 'summary' => null, 'price_text' => null, 'is_flagship' => false, 'is_active' => true,
                'sort_order' => ++$order, 'created_at' => $now, 'updated_at' => $now,
            ], $extra));

            return $ids[$key];
        };

        // 1) Çözüm blokları → hizmetler.
        foreach (DB::table('site_blocks')->where('key', 'solutions')->get() as $row) {
            foreach ((array) json_decode((string) $row->data, true) as $item) {
                if (is_array($item) && ! empty($item['title'])) {
                    $ensure((string) $item['title'], ['summary' => isset($item['desc']) ? mb_substr((string) $item['desc'], 0, 300) : null, 'price_text' => isset($item['price']) ? mb_substr((string) $item['price'], 0, 60) : null, 'is_flagship' => ! empty($item['flagship'])]);
                }
            }
        }

        DB::table('site_blocks')->where('key', 'solutions')->delete();

        // 2) Lokasyon etiketleri → ilişki.
        foreach (DB::table('locations')->select('id', 'tags')->get() as $location) {
            foreach ((array) json_decode((string) $location->tags, true) as $i => $tag) {
                $tag = trim((string) $tag);

                if ($tag === '') {
                    continue;
                }

                DB::table('location_service')->updateOrInsert(['location_id' => $location->id, 'service_id' => $ensure($tag)], ['sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
};
