<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rıza kaydı (audit F-07): vitrin formundaki KVKK onayı, o anki yasal metin sürümüne bağlı; append-only.
 */
class ConsentRecord extends Model
{
    protected $fillable = ['legal_document_version_id', 'kind', 'subject_type', 'subject_id', 'content_hash', 'ip', 'user_agent', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime'];

    /** @return BelongsTo<LegalDocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }
}
