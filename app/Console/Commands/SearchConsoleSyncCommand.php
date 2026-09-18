<?php

namespace App\Console\Commands;

use App\Services\ContentService;
use App\Services\SearchPerformanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Search Console senkronu (faz 60d): son N gün tıklama/gösterim/sıra verisi Gateway üzerinden çekilir, günlük
 * özet tablosuna yazılır. Sağlayıcı kapalı/eksikse yazmaz, durumu kaydeder (sahte veri yok). Zamanlayıcı günlük.
 */
class SearchConsoleSyncCommand extends Command
{
    protected $signature = 'ofisvio:search-console-sync {--days=28 : Geriye dönük gün}';

    protected $description = 'Search Console arama performansı verisini çeker (yalnız sağlayıcı bağlıyken).';

    public function handle(SearchPerformanceService $performance, ContentService $contents): int
    {
        $failed = false;

        foreach ($contents->allWebsites() as $website) {
            try {
                $written = $performance->syncSearchConsole($website, max(7, min(90, (int) $this->option('days'))));
                $this->info($website->name.': '.$written.' satır.');
            } catch (Throwable $e) {
                $failed = true;
                $this->error($website->name.': '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
