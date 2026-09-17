<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rezervasyon dilimi (faz 45): oda + 15 dk dilim başlangıcı tekil. Yalnız BookingService yazar;
 * odayı meşgul eden durumlarda satırlar vardır, serbest kalınca silinir. Tenant scope taşımaz:
 * çakışma tüm şirketler arasında tanımlıdır.
 */
class BookingSlot extends Model
{
    public const MINUTES = 15;

    public $timestamps = false;

    protected $fillable = ['room_id', 'booking_id', 'slot_at'];

    protected $casts = ['slot_at' => 'datetime'];
}
