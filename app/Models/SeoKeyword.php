<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Anahtar kelime kaydı (faz 60c). Yalnız KeywordService yazar. target_path her kayıtta çözülmüş yol olarak
 * tutulur; hedef silinirse "boşluk" olarak raporlanır.
 */
class SeoKeyword extends Model
{
    public const ROLES = ['primary' => 'Birincil', 'secondary' => 'İkincil'];

    public const INTENTS = ['informational' => 'Bilgi', 'commercial' => 'Karşılaştırma', 'transactional' => 'Satın alma', 'navigational' => 'Marka/gezinme', 'local' => 'Yerel'];

    public const TARGET_TYPES = ['content' => 'Yazı / sayfa', 'service' => 'Hizmet', 'location' => 'Lokasyon', 'landing' => 'Hizmet × şehir', 'path' => 'Yol'];

    protected $fillable = ['website_id', 'keyword', 'normalized', 'role', 'intent', 'cluster', 'target_type', 'target_id', 'target_path', 'note', 'created_by'];

    public static function normalize(string $keyword): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($keyword))));
    }
}
