<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel tampungan (typed staging) hasil tarikan OData D365 — kolom sesuai field $select.
 * Terpisah dari tabel raw JSON (d365_gl_entries dst.) agar bisa diquery langsung.
 * Prefix: d365s_ (s = structured/staging typed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. GL Account Entries — GeneralJournalAccountEntryBiEntities
        // ---------------------------------------------------------------
        Schema::create('d365s_gl_entries', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('general_journal_entry', 64)->nullable()->index();
            $table->string('general_journal_account_entry', 64)->nullable();
            $table->string('main_account', 32)->nullable()->index();
            $table->string('account_name', 255)->nullable();
            $table->date('accounting_date')->nullable()->index();
            $table->string('voucher', 64)->nullable()->index();
            $table->string('journal_number', 64)->nullable()->index();
            $table->string('currency_code', 10)->nullable();
            $table->decimal('accounting_currency_amount', 22, 4)->nullable();
            $table->decimal('reporting_currency_amount', 22, 4)->nullable();
            $table->string('posting_type', 64)->nullable();
            $table->string('posting_layer', 32)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('dimension_values', 500)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 2. GL Journal Entries (Header) — GeneralJournalEntryBiEntities
        // ---------------------------------------------------------------
        Schema::create('d365s_gl_journal_entries', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('general_journal_entry', 64)->nullable()->index();
            $table->string('journal_number', 64)->nullable()->index();
            $table->string('voucher', 64)->nullable();
            $table->date('accounting_date')->nullable()->index();
            $table->date('document_date')->nullable();
            $table->string('journal_category', 64)->nullable();
            $table->string('posting_layer', 32)->nullable();
            $table->string('fiscal_calendar_year', 16)->nullable()->index();
            $table->string('fiscal_calendar_period', 16)->nullable()->index();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 3. Main Accounts (Chart of Accounts) — MainAccounts
        // ---------------------------------------------------------------
        Schema::create('d365s_main_accounts', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('main_account_id', 32)->index();
            $table->string('name', 255)->nullable();
            $table->string('type', 64)->nullable();
            $table->string('account_category_description', 255)->nullable();
            $table->string('chart_of_accounts', 64)->nullable()->index();
            $table->string('currency_code', 10)->nullable();
            $table->string('reporting_type', 64)->nullable();
            $table->string('debit_credit_proposal', 32)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 4. Financial Dimension Values — FinancialDimensionValues
        // ---------------------------------------------------------------
        Schema::create('d365s_dimension_values', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('financial_dimension', 64)->index();
            $table->string('legal_entity_id', 16)->index();
            $table->string('dimension_value', 64)->index();
            $table->string('description', 255)->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 5. Ledger Journal Lines — LedgerJournalTransBiEntities
        // ---------------------------------------------------------------
        Schema::create('d365s_ledger_journal_lines', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('journal_batch_number', 64)->nullable()->index();
            $table->unsignedInteger('line_number')->nullable();
            $table->string('voucher', 64)->nullable()->index();
            $table->date('trans_date')->nullable()->index();
            $table->string('account_type', 32)->nullable();
            $table->string('account', 64)->nullable()->index();
            $table->string('account_display_value', 255)->nullable();
            $table->decimal('amount_cur_debit', 22, 4)->nullable();
            $table->decimal('amount_cur_credit', 22, 4)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('txt', 500)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 6. Ledger Entry Journals — LedgerEntryJournalBiEntities
        // ---------------------------------------------------------------
        Schema::create('d365s_ledger_entry_journals', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('journal_batch_number', 64)->nullable()->index();
            $table->string('journal_name', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('journal_type', 64)->nullable();
            $table->boolean('posted')->nullable();
            $table->dateTime('posted_datetime')->nullable()->index();
            $table->unsignedInteger('num_of_lines')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 7. Vendors — VendorsV2
        // ---------------------------------------------------------------
        Schema::create('d365s_vendors', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('vendor_account_number', 64)->index();
            $table->string('vendor_group_id', 32)->nullable()->index();
            $table->string('vendor_organization_name', 255)->nullable();
            $table->string('address_description', 500)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('payment_terms', 32)->nullable();
            $table->string('tax_group', 64)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 8. Customers — CustomersV3
        // ---------------------------------------------------------------
        Schema::create('d365s_customers', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('customer_account_number', 64)->index();
            $table->string('customer_group_id', 32)->nullable()->index();
            $table->string('organization_name', 255)->nullable();
            $table->string('address_description', 500)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('payment_terms', 32)->nullable();
            $table->string('sales_currency_code', 10)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 9. PO Headers — PurchaseOrderHeadersV2
        // ---------------------------------------------------------------
        Schema::create('d365s_po_headers', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('purchase_order_number', 64)->index();
            $table->string('purchase_order_name', 255)->nullable();
            $table->string('order_vendor_account_number', 64)->nullable()->index();
            $table->string('purchase_order_status', 32)->nullable()->index();
            $table->date('accounting_date')->nullable()->index();
            $table->string('currency_code', 10)->nullable();
            $table->string('payment_terms_name', 64)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 10. PO Lines — PurchaseOrderLinesV2
        // ---------------------------------------------------------------
        Schema::create('d365s_po_lines', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('purchase_order_number', 64)->index();
            $table->unsignedInteger('line_number')->nullable();
            $table->string('item_number', 64)->nullable()->index();
            $table->string('line_description', 500)->nullable();
            $table->decimal('ordered_purchase_quantity', 22, 4)->nullable();
            $table->string('purchase_unit_symbol', 16)->nullable();
            $table->decimal('purchase_price', 22, 4)->nullable();
            $table->decimal('line_amount', 22, 4)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 11. Vendor Invoices — VendorInvoiceHeaders
        // ---------------------------------------------------------------
        Schema::create('d365s_vendor_invoices', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('invoice', 64)->index();
            $table->string('vendor_invoice_number', 64)->nullable();
            $table->string('invoice_account', 64)->nullable()->index();
            $table->date('invoice_date')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->decimal('invoice_amount', 22, 4)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 12. Vendor Invoice Lines — VendorInvoiceLines
        // ---------------------------------------------------------------
        Schema::create('d365s_vendor_invoice_lines', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('invoice', 64)->index();
            $table->string('invoice_line', 32)->nullable();
            $table->string('item_number', 64)->nullable()->index();
            $table->string('line_description', 500)->nullable();
            $table->decimal('quantity', 22, 4)->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->decimal('line_amount', 22, 4)->nullable();
            $table->string('main_account_id', 32)->nullable()->index();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // 13. Inventory Transactions — InventTransBiEntities
        // ---------------------------------------------------------------
        Schema::create('d365s_inventory_trans', function (Blueprint $table) {
            $table->string('source_key', 64)->primary();
            $table->string('company', 16)->index();
            $table->string('item_id', 64)->nullable()->index();
            $table->string('invent_site_id', 64)->nullable()->index();
            $table->string('invent_location_id', 64)->nullable();
            $table->string('status_receipt', 32)->nullable();
            $table->string('status_issue', 32)->nullable();
            $table->decimal('qty', 22, 4)->nullable();
            $table->decimal('cost_amount_posted', 22, 4)->nullable();
            $table->decimal('cost_amount_adjustment', 22, 4)->nullable();
            $table->decimal('cost_amount_physical', 22, 4)->nullable();
            $table->date('date_physical')->nullable()->index();
            $table->date('date_financial')->nullable()->index();
            $table->string('voucher', 64)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = [
            'd365s_inventory_trans', 'd365s_vendor_invoice_lines', 'd365s_vendor_invoices',
            'd365s_po_lines', 'd365s_po_headers', 'd365s_customers', 'd365s_vendors',
            'd365s_ledger_entry_journals', 'd365s_ledger_journal_lines',
            'd365s_dimension_values', 'd365s_main_accounts',
            'd365s_gl_journal_entries', 'd365s_gl_entries',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
