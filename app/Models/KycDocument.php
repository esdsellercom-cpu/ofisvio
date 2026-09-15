<?php

namespace App\Models;

use App\Enums\KycDocumentStatus;
use App\Enums\KycDocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KycDocument extends Model
{
    use BelongsToTenant;   // tenant kolonu: company_id (varsayılan)
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'type', 'status', 'original_filename', 'storage_path',
        'mime_type', 'size_bytes', 'checksum_sha256', 'uploaded_by',
    ];

    protected $casts = [
        'type' => KycDocumentType::class,
        'status' => KycDocumentStatus::class,
        'reviewed_at' => 'datetime',
        'physical_received_at' => 'datetime',
        'physical_destroyed_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    /**
     * storage_path RESTRICTED veri: serialize edilen her yerde (API resource,
     * log, exception context) sızmaması için gizlenir. İçeriğe erişim yalnızca
     * KycService üzerinden, JIT kapısından geçerek olur.
     */
    protected $hidden = ['storage_path', 'checksum_sha256'];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPhysicallyHeld(): bool
    {
        return $this->physical_received_at !== null && $this->physical_destroyed_at === null;
    }
}
