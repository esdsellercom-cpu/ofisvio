<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Oda rezervasyonu / talebi (booking engine v2). company_id tenant sınırıdır;
 * vitrinden gelen talepte NULL'dır (müşteri alanları kayıtta). Durum yalnız
 * BookingService::transition ile değişir (BookingStatus durum makinesi).
 */
class Booking extends Model
{
    use BelongsToTenant;

    public const SOURCES = ['site' => 'Web sitesi', 'panel' => 'Müşteri paneli', 'desk' => 'Resepsiyon'];

    protected $fillable = [
        'uuid', 'reference', 'company_id', 'room_id', 'location_id', 'booked_by',
        'customer_name', 'customer_email', 'customer_phone', 'company_name',
        'starts_at', 'ends_at', 'participant_count', 'status', 'source', 'locale',
        'approval_required', 'approved_by', 'approved_at', 'rejected_reason', 'expires_at',
        'checked_in_at', 'completed_at', 'consented_at', 'consent_ip',
        'total_amount', 'note', 'internal_note', 'overridden', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'status' => BookingStatus::class,
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime',
        'approved_at' => 'datetime', 'expires_at' => 'datetime', 'checked_in_at' => 'datetime',
        'completed_at' => 'datetime', 'consented_at' => 'datetime',
        'total_amount' => 'integer', 'participant_count' => 'integer',
        'overridden' => 'boolean', 'approval_required' => 'boolean',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return HasMany<BookingStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** Odayı meşgul ediyor mu (onaylı ya da bekleyen tutma)? */
    public function isActive(): bool
    {
        return $this->status->blocksRoom();
    }

    public function isPending(): bool
    {
        return in_array($this->status, [BookingStatus::REQUESTED, BookingStatus::PENDING_APPROVAL], true);
    }

    public function isUpcoming(): bool
    {
        return $this->isActive() && $this->ends_at->greaterThan(Carbon::now());
    }

    public function hours(): float
    {
        return round($this->starts_at->diffInMinutes($this->ends_at) / 60, 2);
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }

    /** Görünen müşteri adı: panel/masa rezervasyonunda şirket, sitede kişi. */
    public function customerLabel(): string
    {
        return $this->company->legal_name ?? $this->company_name ?? $this->customer_name ?? '—';
    }

    public function contactName(): string
    {
        return $this->customer_name ?? $this->booker->name ?? '—';
    }

    public function contactEmail(): ?string
    {
        return $this->customer_email ?? $this->booker?->email;
    }
}
