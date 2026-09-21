<?php

namespace App\Events;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Events\Dispatchable;

/** Domain olayı (audit F-07): header/footer yayınlandı — footer'daki yasal sayfa seçimi sürüm denetimini tetikler. */
class SiteChromePublished
{
    use Dispatchable;

    public function __construct(public readonly Website $website, public readonly string $area, public readonly ?User $actor) {}
}
