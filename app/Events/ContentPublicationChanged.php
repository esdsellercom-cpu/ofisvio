<?php

namespace App\Events;

use App\Models\Content;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain olayı (faz 44): içerik yayına girdi/güncellendi ($live=true) ya da yayından düştü (arşiv/silme).
 * ContentService DB commit'inden SONRA yayınlar; dinleyiciler arama motoru bildirimi (IndexNow) üretir.
 */
class ContentPublicationChanged
{
    use Dispatchable;

    public function __construct(public readonly Content $content, public readonly bool $live) {}
}
