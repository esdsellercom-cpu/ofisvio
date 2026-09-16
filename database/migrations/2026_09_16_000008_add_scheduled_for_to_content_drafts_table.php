<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 10/24: çalışma taslağı da zamanlanabilir (status SCHEDULED + scheduled_for);
 * content:publish-scheduled zamanı gelen taslağı canlı içeriğe birleştirir.
 * Müşteri rolleri content.publish taşımaz ama content.schedule taşır — yayındaki
 * sayfasını ancak bu yolla güncelleyebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_drafts', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('review_note')->index();
        });
    }

    public function down(): void
    {
        Schema::table('content_drafts', function (Blueprint $table) {
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn('scheduled_for');
        });
    }
};
