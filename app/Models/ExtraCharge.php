<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ek harcama (faz 51): üyelik/hizmet ücreti dışında kalan masraf (toplantı odası, baskı, kargo, hasar…). Tutar kuruş.
 * Fatura kesildiyse invoice_id dolar (borç faturada izlenir); faturasız kayıt bakiyeye doğrudan yansır.
 * Yalnız MemberCenterService yazar.
 */
class ExtraCharge extends Model
{
    use BelongsToTenant;

    public const KINDS = ['meeting_room' => 'Ek toplantı odası', 'print' => 'Baskı', 'shipping' => 'Kargo', 'phone' => 'Telefon', 'service' => 'Ek hizmet', 'damage' => 'Hasar', 'asset' => 'Demirbaş', 'other' => 'Diğer'];

    protected $fillable = ['company_id', 'user_role_id', 'kind', 'description', 'amount', 'currency', 'charged_on', 'invoice_id', 'note', 'created_by'];

    protected $casts = ['charged_on' => 'date', 'amount' => 'integer'];

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
