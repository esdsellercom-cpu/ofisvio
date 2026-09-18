<?php

namespace App\Console\Commands;

use App\Services\ContentRefreshService;
use App\Services\ContentService;
use Illuminate\Console\Command;

/** İçerik yenileme adayı taraması (faz 60e): deterministik sinyaller; --external dış kaynakları Gateway ile yoklar. */
class ContentRefreshScanCommand extends Command
{
    protected $signature = 'ofisvio:content-refresh-scan {--external : Dış kaynak bağlantılarını yokla (Gateway::probe)}';

    protected $description = 'Eski/kırık/trafiği düşen içerikleri yenileme adayı olarak işaretler.';

    public function handle(ContentRefreshService $refresh, ContentService $contents): int
    {
        foreach ($contents->allWebsites() as $website) {
            $this->info($website->name.': '.$refresh->detect($website, (bool) $this->option('external')).' aday.');
        }

        return self::SUCCESS;
    }
}
