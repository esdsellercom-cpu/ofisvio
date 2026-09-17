<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sözleşme (faz 51): şirket (+ isteğe bağlı üye) ile yapılan anlaşma; numara SOZ-YYYY-000001; dosya özel diskte.
 * Durum yalnız MemberCenterService yazar: draft | active | ended. `expired` türetilir (bitiş geçmiş, sonlandırılmamış).
 */
class Contract extends Model
{
    use BelongsToTenant;

    public const TYPES = ['uyelik' => 'Üyelik sözleşmesi', 'sanal_ofis' => 'Sanal ofis', 'hazir_ofis' => 'Hazır ofis', 'coworking' => 'Coworking', 'toplanti' => 'Toplantı & etkinlik', 'hizmet' => 'Hizmet sözleşmesi', 'diger' => 'Diğer'];

    public const STATUSES = ['draft' => 'Taslak', 'active' => 'Aktif', 'ended' => 'Sonlandırıldı', 'expired' => 'Süresi doldu'];

    public const WARN_DAYS = 15;

    protected $fillable = ['company_id', 'user_role_id', 'number', 'type', 'starts_on', 'ends_on', 'status', 'file_path', 'file_name', 'file_mime', 'note', 'created_by', 'ended_by', 'ended_at', 'end_reason'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'ended_at' => 'datetime'];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<UserRole, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'user_role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Görünen durum: aktif ama bitiş geçmişse 'expired'. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'active' && $this->ends_on !== null && $this->ends_on->lt(Carbon::today())) {
            return 'expired';
        }

        return $this->status;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->effectiveStatus()] ?? $this->status;
    }

    /** Bitişe kalan gün (null = süresiz ya da aktif değil; negatif = geçmiş). */
    public function daysLeft(): ?int
    {
        if ($this->status !== 'active' || $this->ends_on === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->ends_on, false);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
