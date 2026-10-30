<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365s_gl_journal_entries', function (Blueprint $table): void {
            $table->string('document_number', 128)->nullable()->index()->after('voucher');
        });
    }

    public function down(): void
    {
        Schema::table('d365s_gl_journal_entries', function (Blueprint $table): void {
            $table->dropColumn('document_number');
        });
    }
};
