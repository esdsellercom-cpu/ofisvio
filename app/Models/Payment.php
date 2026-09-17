<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Tahsilat kaydı (faz 39c): faturaya bağlı, company_id tenant sınırı. */
class Payment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['cash' => 'Nakit', 'card' => 'Kart / POS', 'transfer' => 'Havale / EFT', 'other' => 'Diğer'];

    public const STATUSES = ['recorded' => 'Kayıtlı', 'cancelled' => 'İptal'];

    protected $fillable = ['invoice_id', 'company_id', 'amount', 'currency', 'method', 'description', 'paid_on', 'reference', 'note', 'recorded_by', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason'];

    protected $casts = ['paid_on' => 'date', 'amount' => 'integer', 'cancelled_at' => 'datetime'];

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isCash(): bool
    {
        return $this->method === 'cash';
    }

    /**
     * Bu tahsilat için düzenlenmiş (iptal edilmemiş) makbuz.
     *
     * @return HasOne<Document, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Document::class)->where('kind', 'receipt')->where('status', 'issued');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
