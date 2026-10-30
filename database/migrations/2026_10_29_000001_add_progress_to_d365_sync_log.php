<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365_sync_log', function (Blueprint $table): void {
            $table->unsignedInteger('total')->nullable()->after('pages');
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('d365_sync_log', function (Blueprint $table): void {
            $table->dropColumn(['total', 'progress_percent']);
        });
    }
};
