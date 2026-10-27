<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_matrix_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('parent_code', 32)->nullable()->index();
            $table->boolean('is_parent')->default(false);
            $table->decimal('val_mining', 18, 2)->default(0);
            $table->decimal('val_hauling', 18, 2)->default(0);
            $table->decimal('val_wp', 18, 2)->default(0);
            $table->decimal('val_port', 18, 2)->default(0);
            $table->decimal('cost_mining', 18, 2)->default(0);
            $table->decimal('cost_hauling', 18, 2)->default(0);
            $table->decimal('cost_wp', 18, 2)->default(0);
            $table->decimal('cost_port', 18, 2)->default(0);
            $table->decimal('cost_ga', 18, 2)->default(0);
            $table->decimal('cost_selling', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('gl_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number', 100)->nullable()->index();
            $table->string('voucher', 100)->nullable();
            $table->date('entry_date')->nullable()->index();
            $table->string('account_name')->nullable();
            $table->string('main_account', 32)->index();
            $table->decimal('amount_idr', 18, 2)->default(0);
            $table->string('valuation_to')->nullable()->index();
            $table->string('costing_to')->nullable()->index();
            $table->json('source_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('production_tickets', function (Blueprint $table) {
            $table->id();
            $table->date('exit_date')->nullable()->index();
            $table->string('exit_time', 20)->nullable();
            $table->string('product')->nullable()->index();
            $table->string('truck_number')->nullable();
            $table->string('driver')->nullable();
            $table->decimal('gross_kg', 18, 2)->default(0);
            $table->decimal('tare_kg', 18, 2)->default(0);
            $table->decimal('net_kg', 18, 2)->default(0);
            $table->string('weighbridge')->nullable()->index();
            $table->string('supplier')->nullable();
            $table->string('operator')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->json('setting_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_settings');
        Schema::dropIfExists('production_tickets');
        Schema::dropIfExists('gl_entries');
        Schema::dropIfExists('cost_matrix_definitions');
    }
};
