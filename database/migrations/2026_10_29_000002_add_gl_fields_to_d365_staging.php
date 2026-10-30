<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('d365s_gl_entries', function (Blueprint $table): void {
            $table->string('ledger_account', 255)->nullable()->after('account_name');
            $table->decimal('transaction_currency_amount', 22, 4)->nullable()->after('reporting_currency_amount');
            $table->decimal('quantity', 22, 4)->nullable()->after('transaction_currency_amount');
            $table->string('project_id', 64)->nullable()->index()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('d365s_gl_entries', function (Blueprint $table): void {
            $table->dropColumn(['ledger_account', 'transaction_currency_amount', 'quantity', 'project_id']);
        });
    }
};
