<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yasal metin sürümü (audit F-07): değişmez kayıt — yalnız LegalDocumentService::publishIfChanged yazar.
 */
class LegalDocumentVersion extends Model
{
    public const KINDS = ['kvkk' => 'KVKK / aydınlatma', 'privacy' => 'Gizlilik politikası', 'cookies' => 'Çerez politikası', 'terms' => 'Kullanım koşulları'];

    protected $fillable = ['website_id', 'kind', 'version', 'content_id', 'title', 'content_hash', 'published_at', 'published_by', 'note'];

    protected $casts = ['version' => 'int', 'published_at' => 'datetime'];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
