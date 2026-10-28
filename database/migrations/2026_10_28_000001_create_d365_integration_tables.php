<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** [sync_log_key, table_name] — tabel staging generik per data entity D365. */
    private const ENTITY_TABLES = [
        'gl_entries' => 'd365_gl_entries',
        'gl_journal_entries' => 'd365_gl_journal_entries',
        'main_accounts' => 'd365_main_accounts',
        'dimension_values' => 'd365_dimension_values',
        'ledger_journal_lines' => 'd365_ledger_journal_lines',
        'ledger_entry_journals' => 'd365_ledger_entry_journals',
        'vendors' => 'd365_vendors',
        'customers' => 'd365_customers',
        'po_headers' => 'd365_po_headers',
        'po_lines' => 'd365_po_lines',
        'vendor_invoices' => 'd365_vendor_invoices',
        'vendor_invoice_lines' => 'd365_vendor_invoice_lines',
        'inventory_trans' => 'd365_inventory_trans',
    ];

    public function up(): void
    {
        Schema::create('d365_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('entity_key', 48)->index();
            $table->string('entity_name', 128);
            $table->enum('status', ['running', 'success', 'failed'])->default('running')->index();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->string('max_source_key', 64)->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->timestamps();
        });

        foreach (self::ENTITY_TABLES as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->string('source_key', 64);
                $table->json('payload');
                $table->timestamps();
                $table->primary('source_key');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::ENTITY_TABLES) as $tableName) {
            Schema::dropIfExists($tableName);
        }
        Schema::dropIfExists('d365_sync_log');
    }
};
