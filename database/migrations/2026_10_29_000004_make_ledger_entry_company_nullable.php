<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365s_ledger_entry_journals', function (Blueprint $table): void {
            $table->string('company', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('d365s_ledger_entry_journals', function (Blueprint $table): void {
            $table->string('company', 16)->nullable(false)->change();
        });
    }
};
