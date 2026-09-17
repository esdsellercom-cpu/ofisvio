<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Belge (faz 47): tahsilat makbuzu ya da geciken ödeme belgesi. Numara türe+yıla göre sıralı, değerler
 * belge anında `data`'ya dondurulur (fatura/tahsilat sonradan değişse de belge aynı kalır); düzenleme yalnız
 * metin alanlarında ve audit'lidir. Silinmez, iptal edilir. Tenant scope yok: finans personeli global.
 */
class Document extends Model
{
    public const KINDS = ['receipt' => 'Tahsilat makbuzu', 'overdue_notice' => 'Geciken ödeme belgesi'];

    public const PREFIX = ['receipt' => 'MKB', 'overdue_notice' => 'GOB'];

    protected $fillable = ['kind', 'number', 'company_id', 'invoice_id', 'payment_id', 'data', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason'];

    protected $casts = ['data' => 'array', 'cancelled_at' => 'datetime'];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
