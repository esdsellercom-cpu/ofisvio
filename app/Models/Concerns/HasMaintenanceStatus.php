<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * Bakım durumu (faz 45): maintenance_until bugün ya da ileri bir tarihse varlık "bakımda" —
 * rezervasyon/tahsis kapalı, panelde rozet. Tarih geçince kendiliğinden biter (zamanlayıcı gerekmez).
 * Kullanan model: maintenance_until (date cast), maintenance_note, is_active.
 */
trait HasMaintenanceStatus
{
    public function isUnderMaintenance(): bool
    {
        $until = $this->maintenance_until;

        return $until !== null && Carbon::parse($until)->endOfDay()->isFuture();
    }

    /** Operasyon durumu: active | maintenance | inactive */
    public function operationalStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        return $this->isUnderMaintenance() ? 'maintenance' : 'active';
    }

    public function operationalLabel(): string
    {
        return match ($this->operationalStatus()) {
            'inactive' => 'Pasif',
            'maintenance' => 'Bakımda',
            default => 'Aktif',
        };
    }
}
