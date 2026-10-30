<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['d365s_vendor_invoice_lines', 'd365s_vendor_invoices', 'd365_vendor_invoice_lines', 'd365_vendor_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: Vendor Invoice entities were removed.
    }
};
