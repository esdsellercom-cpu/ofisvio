<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * URL geçmişi (faz 54): bir içeriğin/hizmetin/lokasyonun geçmişte kullandığı yollar. Silinen kayıt için başlık/kategori/
 * etiket anlık görüntüsü tutulur ki sonradan gelen 404 için benzerlik eşlemesi yapılabilsin.
 *
 * @property int $id
 * @property int $website_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $old_path
 * @property string|null $new_path
 * @property string $reason
 * @property array<string, mixed>|null $snapshot
 */
class ContentUrlHistory extends Model
{
    protected $table = 'content_url_history';

    public const REASONS = ['slug_change' => 'Slug değişti', 'parent_change' => 'Üst sayfa değişti', 'deleted' => 'Silindi', 'unpublished' => 'Yayından kaldırıldı (taslak)', 'archived' => 'Arşivlendi'];

    protected $fillable = ['website_id', 'entity_type', 'entity_id', 'old_path', 'new_path', 'reason', 'snapshot'];

    protected $casts = ['snapshot' => 'array'];
}
