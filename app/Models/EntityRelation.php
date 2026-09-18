<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Varlık ilişkisi (faz 60b, Knowledge Graph): content → service/location (yazı hangi hizmet/şube hakkında),
 * topic → entity (konu kümesi hangi varlığa bağlı). Service ↔ Location ilişkisi burada değil, location_service
 * pivotundadır. Yalnız EntityGraphService yazar.
 */
class EntityRelation extends Model
{
    public const TYPES = ['content' => 'Yazı / sayfa', 'service' => 'Hizmet', 'location' => 'Lokasyon', 'topic' => 'Konu'];

    public const RELATIONS = ['about' => 'hakkında', 'related' => 'ilgili', 'serves' => 'sunulur', 'mentions' => 'değinir'];

    protected $fillable = ['website_id', 'from_type', 'from_id', 'to_type', 'to_id', 'relation'];
}
