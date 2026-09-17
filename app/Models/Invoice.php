<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Fatura (faz 39c): company_id tenant sınırı. Durum yalnız InvoiceService yazar. */
class Invoice extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft' => 'Taslak', 'issued' => 'Yayınlandı', 'overdue' => 'Gecikmiş', 'paid' => 'Ödendi', 'cancelled' => 'İptal'];

    /** Tahsilat bekleyen durumlar. */
    public const OPEN = ['issued', 'overdue'];

    protected $fillable = [
        'number', 'company_id', 'subscription_id', 'booking_id', 'status', 'description', 'subtotal', 'tax_rate', 'tax_amount',
        'total', 'paid_amount', 'currency', 'issued_on', 'due_on', 'paid_at', 'note', 'created_by', 'issued_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'issued_on' => 'date', 'due_on' => 'date', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
        'subtotal' => 'integer', 'tax_rate' => 'integer', 'tax_amount' => 'integer', 'total' => 'integer', 'paid_amount' => 'integer',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function outstanding(): int
    {
        return max(0, $this->total - $this->paid_amount);
    }

    /** Vade geçen gün (negatifse vadeye kalan). */
    public function daysOverdue(): int
    {
        return $this->due_on === null ? 0 : (int) $this->due_on->diffInDays(Carbon::today(), false);
    }
}
