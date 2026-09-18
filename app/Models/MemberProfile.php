<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Üye profili (faz 51): şirket üyeliğine (user_roles) bağlı kişi bilgileri + üye numarası. Yalnız MemberCenterService yazar.
 * Kimlik/vergi numarası kişisel veridir; listede maskelenir.
 */
class MemberProfile extends Model
{
    public const MEMBERSHIP_TYPES = ['sanal_ofis' => 'Sanal ofis', 'hazir_ofis' => 'Hazır ofis', 'coworking' => 'Coworking', 'gunluk_kullanim' => 'Günlük kullanım', 'toplanti' => 'Toplantı & etkinlik', 'diger' => 'Diğer'];

    protected $fillable = ['user_role_id', 'member_no', 'first_name', 'last_name', 'title', 'phone', 'identity_number', 'address', 'city', 'country', 'membership_type', 'member_since', 'note', 'avatar_media_id', 'updated_by'];

    protected $casts = ['member_since' => 'date'];

    /** @return BelongsTo<UserRole, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'user_role_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** Kimlik/vergi no maskeli (son 3 hane). */
    public function maskedIdentity(): string
    {
        $n = (string) ($this->identity_number ?? '');

        return $n === '' ? '' : str_repeat('*', max(0, mb_strlen($n) - 3)).mb_substr($n, -3);
    }
}
