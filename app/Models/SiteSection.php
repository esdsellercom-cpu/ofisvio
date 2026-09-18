<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ana sayfa bölümü (TASLAK). Yalnız SiteBuilderService yazar. */
class SiteSection extends Model
{
    protected $fillable = ['website_id', 'type', 'anchor', 'sort_order', 'is_visible', 'hide_on_mobile', 'hide_on_desktop', 'locked', 'label', 'preset_id', 'settings', 'publish_from', 'publish_until', 'updated_by'];

    protected $casts = [
        'settings' => 'array', 'is_visible' => 'boolean', 'hide_on_mobile' => 'boolean', 'hide_on_desktop' => 'boolean', 'locked' => 'boolean',
        'publish_from' => 'datetime', 'publish_until' => 'datetime', 'sort_order' => 'integer',
    ];

    /** Anlık görüntü satırı (yayın/geri alma). @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'anchor' => $this->anchor, 'is_visible' => $this->is_visible,
            'hide_on_mobile' => $this->hide_on_mobile, 'hide_on_desktop' => $this->hide_on_desktop, 'locked' => $this->locked, 'label' => $this->label, 'preset_id' => $this->preset_id,
            'settings' => $this->settings ?? [], 'publish_from' => $this->publish_from?->toIso8601String(), 'publish_until' => $this->publish_until?->toIso8601String(),
        ];
    }
}
