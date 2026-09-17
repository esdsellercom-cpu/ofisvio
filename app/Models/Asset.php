<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demirbaş (faz 46): lokasyona ait taşınır varlık (sandalye, monitör, dolap…). İsteğe bağlı bir alana
 * yerleşiktir; tahsisle birlikte üyeye/şirkete verilince space_assignment_id dolar ve status 'assigned' olur,
 * tahsis bitince serbest kalır. Tenant scope taşımaz (Ofisvio envanteri); yazma yalnız AssetService.
 */
class Asset extends Model
{
    public const CATEGORIES = ['furniture' => 'Mobilya', 'electronics' => 'Elektronik', 'it' => 'BT / bilgisayar', 'decoration' => 'Dekorasyon', 'other' => 'Diğer'];

    public const STATUSES = ['available' => 'Müsait', 'assigned' => 'Tahsisli', 'maintenance' => 'Bakımda', 'retired' => 'Hurda / kullanım dışı'];

    protected $fillable = ['location_id', 'space_id', 'space_assignment_id', 'name', 'code', 'category', 'serial', 'status', 'notes', 'created_by'];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<SpaceAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SpaceAssignment::class, 'space_assignment_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isAssignable(): bool
    {
        return $this->status === 'available';
    }
}
