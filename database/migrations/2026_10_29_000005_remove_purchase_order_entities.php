<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['d365s_po_lines', 'd365s_po_headers', 'd365_po_lines', 'd365_po_headers'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: PO entities were removed from the application.
    }
};
