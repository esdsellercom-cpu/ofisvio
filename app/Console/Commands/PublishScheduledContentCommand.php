<?php

namespace App\Console\Commands;

use App\Services\ContentService;
use Illuminate\Console\Command;

/**
 * Zamanı gelen içerikleri yayınlar. routes/console.php dakikada bir çalıştırır;
 * üretimde `php artisan schedule:run` cron'da olmalıdır.
 */
class PublishScheduledContentCommand extends Command
{
    protected $signature = 'content:publish-scheduled';

    protected $description = 'SCHEDULED durumundaki, zamanı gelmiş içerikleri yayınlar.';

    public function handle(ContentService $contents): int
    {
        $count = $contents->publishScheduled();

        $this->info($count > 0 ? "{$count} içerik yayınlandı." : 'Zamanı gelen içerik yok.');

        return self::SUCCESS;
    }
}
