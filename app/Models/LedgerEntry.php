<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only defter satırı (audit F-15): yalnız InvoiceService yazar; güncelleme/silme model düzeyinde reddedilir
 * (yanlış kayıt = yeni "correction" satırı). Tenant sınırı company_id.
 */
class LedgerEntry extends Model
{
    use BelongsToTenant;

    public const TYPES = ['invoice_issued' => 'Fatura kesildi', 'payment_received' => 'Tahsilat', 'payment_reversed' => 'Tahsilat iptali', 'invoice_cancelled' => 'Fatura iptali', 'correction' => 'Düzeltme'];

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'invoice_id', 'payment_id', 'type', 'amount', 'currency', 'balance_after', 'memo', 'created_by'];

    protected $casts = ['amount' => 'int', 'balance_after' => 'int', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Defter satırı güncellenemez; düzeltme kaydı ekleyin.'));
        static::deleting(fn () => throw new LogicException('Defter satırı silinemez.'));
    }

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

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
