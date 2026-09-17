<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tahsilat kaydı (faz 39c): faturaya bağlı, company_id tenant sınırı. */
class Payment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['transfer' => 'Havale / EFT', 'card' => 'Kart', 'cash' => 'Nakit', 'other' => 'Diğer'];

    protected $fillable = ['invoice_id', 'company_id', 'amount', 'method', 'paid_on', 'reference', 'note', 'recorded_by'];

    protected $casts = ['paid_on' => 'date', 'amount' => 'integer'];

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
