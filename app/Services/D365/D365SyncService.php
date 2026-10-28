<?php

namespace App\Services\D365;

use App\Services\DashboardService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menarik data entity OData D365 ke tabel staging MySQL (satu tabel per entity)
 * dengan strategi incremental (kursor SourceKey) untuk entity yang memilikinya,
 * dan full-refresh upsert berbasi natural key untuk master data.
 */
class D365SyncService
{
    /**
     * Registry entity terverifikasi terhadap environment sandbox BPIMR.
     * mode: 'incremental' = filter SourceKey gt kursor; 'snapshot' = pull penuh natural key.
     * dataAreaField: nama field company di entity (SubledgerVoucherDataAreaId / dataAreaId / LegalEntityId).
     * select: daftar kolom terbatas yang diperlukan oleh dashboard & reporting staging.
     */
    public const ENTITIES = [
        [
            'key' => 'gl_entries', 'label' => 'GL Account Entries (Baris GL)',
            'entity' => 'GeneralJournalAccountEntryBiEntities', 'table' => 'd365_gl_entries',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'SubledgerVoucherDataAreaId',
            'select' => [
                'SourceKey', 'SubledgerVoucherDataAreaId', 'GeneralJournalEntry', 'GeneralJournalAccountEntry',
                'MainAccount', 'AccountName', 'AccountingDate', 'Voucher', 'JournalNumber',
                'TransactionCurrencyCode', 'AccountingCurrencyAmount', 'ReportingCurrencyAmount',
                'PostingType', 'PostingLayer', 'Description', 'DimensionValues',
            ],
        ],
        [
            'key' => 'gl_journal_entries', 'label' => 'GL Journal Entries (Header GL)',
            'entity' => 'GeneralJournalEntryBiEntities', 'table' => 'd365_gl_journal_entries',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'SubledgerVoucherDataAreaId',
            'select' => [
                'SourceKey', 'SubledgerVoucherDataAreaId', 'GeneralJournalEntry', 'JournalNumber',
                'Voucher', 'AccountingDate', 'DocumentDate', 'JournalCategory', 'PostingLayer',
                'FiscalCalendarYear', 'FiscalCalendarPeriod', 'Description',
            ],
        ],
        [
            'key' => 'main_accounts', 'label' => 'Main Accounts (Chart of Accounts)',
            'entity' => 'MainAccounts', 'table' => 'd365_main_accounts',
            'mode' => 'snapshot', 'sourceKey' => 'MainAccountId',
            'dataAreaField' => null, // Global Chart of Accounts (COA) shared across legal entities
            'select' => [
                'MainAccountId', 'Name', 'Type', 'AccountCategoryDescription', 'ChartOfAccounts',
                'CurrencyCode', 'ReportingType', 'DebitCreditProposal',
            ],
        ],
        [
            'key' => 'dimension_values', 'label' => 'Financial Dimension Values',
            'entity' => 'FinancialDimensionValues', 'table' => 'd365_dimension_values',
            'mode' => 'snapshot', 'sourceKey' => ['FinancialDimension', 'LegalEntityId', 'DimensionValue'],
            'dataAreaField' => 'LegalEntityId',
            'select' => [
                'FinancialDimension', 'LegalEntityId', 'DimensionValue', 'Description', 'ActiveFrom', 'ActiveTo',
            ],
        ],
        [
            'key' => 'ledger_journal_lines', 'label' => 'Ledger Journal Trans (Jurnal Operasional)',
            'entity' => 'LedgerJournalTransBiEntities', 'table' => 'd365_ledger_journal_lines',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'SourceKey', 'dataAreaId', 'JournalBatchNumber', 'LineNumber', 'Voucher',
                'TransDate', 'AccountType', 'Account', 'AccountDisplayValue',
                'AmountCurDebit', 'AmountCurCredit', 'Currency', 'Txt',
            ],
        ],
        [
            'key' => 'ledger_entry_journals', 'label' => 'Ledger Entry Journals (Jurnal Aktif)',
            'entity' => 'LedgerEntryJournalBiEntities', 'table' => 'd365_ledger_entry_journals',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'SourceKey', 'dataAreaId', 'JournalBatchNumber', 'JournalName', 'Description',
                'JournalType', 'Posted', 'PostedDateTime', 'NumOfLines',
            ],
        ],
        [
            'key' => 'vendors', 'label' => 'Vendors (Master Vendor)',
            'entity' => 'VendorsV2', 'table' => 'd365_vendors',
            'mode' => 'snapshot', 'sourceKey' => 'VendorAccountNumber',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'VendorAccountNumber', 'dataAreaId', 'VendorGroupId', 'VendorOrganizationName',
                'AddressDescription', 'CurrencyCode', 'PaymentTerms', 'TaxGroup',
            ],
        ],
        [
            'key' => 'customers', 'label' => 'Customers (Master Customer)',
            'entity' => 'CustomersV3', 'table' => 'd365_customers',
            'mode' => 'snapshot', 'sourceKey' => 'CustomerAccountNumber',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'CustomerAccountNumber', 'dataAreaId', 'CustomerGroupId', 'OrganizationName',
                'AddressDescription', 'CurrencyCode', 'PaymentTerms', 'SalesCurrencyCode',
            ],
        ],
        [
            'key' => 'po_headers', 'label' => 'Purchase Order Headers',
            'entity' => 'PurchaseOrderHeadersV2', 'table' => 'd365_po_headers',
            'mode' => 'snapshot', 'sourceKey' => 'PurchaseOrderNumber',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'PurchaseOrderNumber', 'dataAreaId', 'PurchaseOrderName', 'OrderVendorAccountNumber',
                'PurchaseOrderStatus', 'AccountingDate', 'CurrencyCode', 'PaymentTermsName',
            ],
        ],
        [
            'key' => 'po_lines', 'label' => 'Purchase Order Lines',
            'entity' => 'PurchaseOrderLinesV2', 'table' => 'd365_po_lines',
            'mode' => 'snapshot', 'sourceKey' => ['PurchaseOrderNumber', 'LineNumber'],
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'PurchaseOrderNumber', 'LineNumber', 'dataAreaId', 'ItemNumber', 'LineDescription',
                'OrderedPurchaseQuantity', 'PurchaseUnitSymbol', 'PurchasePrice', 'LineAmount',
            ],
        ],
        [
            'key' => 'vendor_invoices', 'label' => 'Vendor Invoice Headers',
            'entity' => 'VendorInvoiceHeaders', 'table' => 'd365_vendor_invoices',
            'mode' => 'snapshot', 'sourceKey' => 'Invoice',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'Invoice', 'dataAreaId', 'VendorInvoiceNumber', 'InvoiceAccount', 'InvoiceDate',
                'DueDate', 'InvoiceAmount', 'Currency', 'Description',
            ],
        ],
        [
            'key' => 'vendor_invoice_lines', 'label' => 'Vendor Invoice Lines',
            'entity' => 'VendorInvoiceLines', 'table' => 'd365_vendor_invoice_lines',
            'mode' => 'snapshot', 'sourceKey' => ['Invoice', 'InvoiceLine'],
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'Invoice', 'InvoiceLine', 'dataAreaId', 'ItemNumber', 'LineDescription',
                'Quantity', 'Unit', 'UnitPrice', 'LineAmount', 'MainAccountId',
            ],
        ],
        [
            'key' => 'inventory_trans', 'label' => 'Inventory Transactions (Invent Trans)',
            'entity' => 'InventTransBiEntities', 'table' => 'd365_inventory_trans',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'SourceKey', 'dataAreaId', 'ItemId', 'InventSiteId', 'InventLocationId',
                'StatusReceipt', 'StatusIssue', 'Qty', 'CostAmountPosted', 'CostAmountAdjustment',
                'CostAmountPhysical', 'DatePhysical', 'DateFinancial', 'Voucher',
            ],
        ],
    ];

    /**
     * Mapping dari entity key ke tabel tampungan bertipe (d365s_*).
     * Setiap entity mendefinisikan mapper untuk mengubah row OData ke kolom tabel.
     */
    public const TYPED_TABLE_MAP = [
        'gl_entries' => [
            'table' => 'd365s_gl_entries',
            'map' => [
                'SubledgerVoucherDataAreaId' => 'company',
                'GeneralJournalEntry'        => 'general_journal_entry',
                'GeneralJournalAccountEntry' => 'general_journal_account_entry',
                'MainAccount'               => 'main_account',
                'AccountName'               => 'account_name',
                'AccountingDate'            => 'accounting_date',
                'Voucher'                   => 'voucher',
                'JournalNumber'             => 'journal_number',
                'TransactionCurrencyCode'   => 'currency_code',
                'AccountingCurrencyAmount'  => 'accounting_currency_amount',
                'ReportingCurrencyAmount'   => 'reporting_currency_amount',
                'PostingType'               => 'posting_type',
                'PostingLayer'              => 'posting_layer',
                'Description'               => 'description',
                'DimensionValues'           => 'dimension_values',
            ],
        ],
        'gl_journal_entries' => [
            'table' => 'd365s_gl_journal_entries',
            'map' => [
                'SubledgerVoucherDataAreaId' => 'company',
                'GeneralJournalEntry'        => 'general_journal_entry',
                'JournalNumber'              => 'journal_number',
                'Voucher'                    => 'voucher',
                'AccountingDate'             => 'accounting_date',
                'DocumentDate'               => 'document_date',
                'JournalCategory'            => 'journal_category',
                'PostingLayer'               => 'posting_layer',
                'FiscalCalendarYear'         => 'fiscal_calendar_year',
                'FiscalCalendarPeriod'       => 'fiscal_calendar_period',
                'Description'                => 'description',
            ],
        ],
        'main_accounts' => [
            'table' => 'd365s_main_accounts',
            'map' => [
                'MainAccountId'              => 'main_account_id',
                'Name'                       => 'name',
                'Type'                       => 'type',
                'AccountCategoryDescription' => 'account_category_description',
                'ChartOfAccounts'            => 'chart_of_accounts',
                'CurrencyCode'               => 'currency_code',
                'ReportingType'              => 'reporting_type',
                'DebitCreditProposal'        => 'debit_credit_proposal',
            ],
        ],
        'dimension_values' => [
            'table' => 'd365s_dimension_values',
            'map' => [
                'FinancialDimension' => 'financial_dimension',
                'LegalEntityId'      => 'legal_entity_id',
                'DimensionValue'     => 'dimension_value',
                'Description'        => 'description',
                'ActiveFrom'         => 'active_from',
                'ActiveTo'           => 'active_to',
            ],
        ],
        'ledger_journal_lines' => [
            'table' => 'd365s_ledger_journal_lines',
            'map' => [
                'dataAreaId'          => 'company',
                'JournalBatchNumber'  => 'journal_batch_number',
                'LineNumber'          => 'line_number',
                'Voucher'             => 'voucher',
                'TransDate'           => 'trans_date',
                'AccountType'         => 'account_type',
                'Account'             => 'account',
                'AccountDisplayValue' => 'account_display_value',
                'AmountCurDebit'      => 'amount_cur_debit',
                'AmountCurCredit'     => 'amount_cur_credit',
                'Currency'            => 'currency',
                'Txt'                 => 'txt',
            ],
        ],
        'ledger_entry_journals' => [
            'table' => 'd365s_ledger_entry_journals',
            'map' => [
                'dataAreaId'         => 'company',
                'JournalBatchNumber' => 'journal_batch_number',
                'JournalName'        => 'journal_name',
                'Description'        => 'description',
                'JournalType'        => 'journal_type',
                'Posted'             => 'posted',
                'PostedDateTime'     => 'posted_datetime',
                'NumOfLines'         => 'num_of_lines',
            ],
        ],
        'vendors' => [
            'table' => 'd365s_vendors',
            'map' => [
                'dataAreaId'             => 'company',
                'VendorAccountNumber'    => 'vendor_account_number',
                'VendorGroupId'          => 'vendor_group_id',
                'VendorOrganizationName' => 'vendor_organization_name',
                'AddressDescription'     => 'address_description',
                'CurrencyCode'           => 'currency_code',
                'PaymentTerms'           => 'payment_terms',
                'TaxGroup'               => 'tax_group',
            ],
        ],
        'customers' => [
            'table' => 'd365s_customers',
            'map' => [
                'dataAreaId'            => 'company',
                'CustomerAccountNumber' => 'customer_account_number',
                'CustomerGroupId'       => 'customer_group_id',
                'OrganizationName'      => 'organization_name',
                'AddressDescription'    => 'address_description',
                'CurrencyCode'          => 'currency_code',
                'PaymentTerms'          => 'payment_terms',
                'SalesCurrencyCode'     => 'sales_currency_code',
            ],
        ],
        'po_headers' => [
            'table' => 'd365s_po_headers',
            'map' => [
                'dataAreaId'               => 'company',
                'PurchaseOrderNumber'      => 'purchase_order_number',
                'PurchaseOrderName'        => 'purchase_order_name',
                'OrderVendorAccountNumber' => 'order_vendor_account_number',
                'PurchaseOrderStatus'      => 'purchase_order_status',
                'AccountingDate'           => 'accounting_date',
                'CurrencyCode'             => 'currency_code',
                'PaymentTermsName'         => 'payment_terms_name',
            ],
        ],
        'po_lines' => [
            'table' => 'd365s_po_lines',
            'map' => [
                'dataAreaId'              => 'company',
                'PurchaseOrderNumber'     => 'purchase_order_number',
                'LineNumber'              => 'line_number',
                'ItemNumber'              => 'item_number',
                'LineDescription'         => 'line_description',
                'OrderedPurchaseQuantity' => 'ordered_purchase_quantity',
                'PurchaseUnitSymbol'      => 'purchase_unit_symbol',
                'PurchasePrice'           => 'purchase_price',
                'LineAmount'              => 'line_amount',
            ],
        ],
        'vendor_invoices' => [
            'table' => 'd365s_vendor_invoices',
            'map' => [
                'dataAreaId'          => 'company',
                'Invoice'             => 'invoice',
                'VendorInvoiceNumber' => 'vendor_invoice_number',
                'InvoiceAccount'      => 'invoice_account',
                'InvoiceDate'         => 'invoice_date',
                'DueDate'             => 'due_date',
                'InvoiceAmount'       => 'invoice_amount',
                'Currency'            => 'currency',
                'Description'         => 'description',
            ],
        ],
        'vendor_invoice_lines' => [
            'table' => 'd365s_vendor_invoice_lines',
            'map' => [
                'dataAreaId'      => 'company',
                'Invoice'         => 'invoice',
                'InvoiceLine'     => 'invoice_line',
                'ItemNumber'      => 'item_number',
                'LineDescription' => 'line_description',
                'Quantity'        => 'quantity',
                'Unit'            => 'unit',
                'UnitPrice'       => 'unit_price',
                'LineAmount'      => 'line_amount',
                'MainAccountId'   => 'main_account_id',
            ],
        ],
        'inventory_trans' => [
            'table' => 'd365s_inventory_trans',
            'map' => [
                'dataAreaId'           => 'company',
                'ItemId'               => 'item_id',
                'InventSiteId'         => 'invent_site_id',
                'InventLocationId'     => 'invent_location_id',
                'StatusReceipt'        => 'status_receipt',
                'StatusIssue'          => 'status_issue',
                'Qty'                  => 'qty',
                'CostAmountPosted'     => 'cost_amount_posted',
                'CostAmountAdjustment' => 'cost_amount_adjustment',
                'CostAmountPhysical'   => 'cost_amount_physical',
                'DatePhysical'         => 'date_physical',
                'DateFinancial'        => 'date_financial',
                'Voucher'              => 'voucher',
            ],
        ],
    ];

    /** Ukuran halaman OData per pull. */
    private const PAGE_SIZE = 2000;

    /** Batas pengaman jumlah halaman per sync per entity. */
    private const MAX_PAGES = 2000;

    public function __construct(private readonly DashboardService $dashboard) {}

    public function client(): D365ODataClient
    {
        return D365ODataClient::fromSettings($this->dashboard->settings());
    }

    public function entityByKey(string $key): ?array
    {
        return collect(self::ENTITIES)->first(fn ($entity) => $entity['key'] === $key);
    }

    /** Jalankan sync untuk satu entity; kembalikan ringkasan hasilnya. */
    public function syncEntity(array $entity): array
    {
        $logId = $this->openLog($entity);

        try {
            $sourceKeyField = $entity['sourceKey'];
            $isScalarKey = is_string($sourceKeyField);
            $result = match ($entity['mode']) {
                'incremental' => $this->pullIncremental($entity, $logId),
                'snapshot' => $isScalarKey
                    ? $this->pullSnapshot($entity, [$sourceKeyField], $logId)
                    : $this->pullSnapshot($entity, $sourceKeyField, $logId),
                default => throw new D365Exception("Mode sync tidak dikenal: {$entity['mode']}"),
            };

            $this->closeLog($logId, 'success', $result);

            return ['entity' => $entity, 'status' => 'success'] + $result;
        } catch (\Throwable $exception) {
            $this->closeLog($logId, 'failed', [
                'fetched' => 0, 'inserted' => 0, 'updated' => 0,
                'maxSourceKey' => null, 'pages' => 0,
            ], $exception->getMessage());

            throw $exception;
        }
    }

    /** Sync semua entity; kembalikan daftar ringkasan per entity. */
    public function syncAll(): array
    {
        $results = [];
        foreach (self::ENTITIES as $entity) {
            try {
                $results[] = $this->syncEntity($entity);
            } catch (\Throwable $exception) {
                $entityTable = $entity['table'];
                $results[] = [
                    'entity' => $entity, 'status' => 'failed',
                    'fetched' => 0, 'inserted' => 0, 'updated' => 0,
                    'error' => $exception->getMessage()." [$entityTable]",
                ];
            }
        }

        return $results;
    }

    /** Target legal entity / company ID untuk filter OData. */
    public const DEFAULT_COMPANY = 'BP';

    private function buildEntityFilter(array $entity, ?string $additionalFilter = null): ?string
    {
        $parts = [];

        $companyField = $entity['dataAreaField'] ?? null;
        if (filled($companyField)) {
            $company = $this->targetCompany();
            $parts[] = sprintf("%s eq '%s'", $companyField, $company);
        }

        if (filled($additionalFilter)) {
            $parts[] = "({$additionalFilter})";
        }

        if (count($parts) === 0) {
            return null;
        }

        return implode(' and ', $parts);
    }

    public function targetCompany(): string
    {
        $settings = $this->dashboard->settings();

        return (string) data_get($settings, 'd365Config.company', self::DEFAULT_COMPANY);
    }

    private function pullIncremental(array $entity, int $logId): array
    {
        $field = (string) $entity['sourceKey'];
        $cursor = $this->lastMaxKey($entity);
        $incrementalFilter = $cursor !== null ? sprintf('%s gt %s', $field, $cursor) : null;
        $filter = $this->buildEntityFilter($entity, $incrementalFilter);

        return $this->pullRows($entity, $field, $filter, $logId);
    }

    private function pullSnapshot(array $entity, array $keyFields, int $logId): array
    {
        $client = $this->client();
        $rawRows = [];
        $typedRows = [];
        $pages = 0;
        $filter = $this->buildEntityFilter($entity);
        $select = $entity['select'] ?? null;
        $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;

        foreach ($client->streamEntity($entity['entity'], select: $select, filter: $filter, top: self::PAGE_SIZE, maxPages: self::MAX_PAGES) as $row) {
            $staging = $this->toStaging($row, $keyFields);
            $rawRows[] = $staging;
            if ($typedMeta !== null) {
                $typedRows[] = $this->toTyped($row, $typedMeta['map'], $staging['source_key']);
            }
            if (count($rawRows) >= self::PAGE_SIZE) {
                $pages++;
            }
        }

        // Master data: refresh penuh hanya setelah seluruh data berhasil ditarik.
        DB::table($entity['table'])->delete();
        foreach (array_chunk($rawRows, 500) as $chunk) {
            DB::table($entity['table'])->insertOrIgnore($chunk);
        }
        // Typed: delete & re-insert supaya sinkron dengan raw.
        if ($typedMeta !== null && count($typedRows) > 0) {
            DB::table($typedMeta['table'])->delete();
            foreach (array_chunk($typedRows, 500) as $chunk) {
                DB::table($typedMeta['table'])->insertOrIgnore($chunk);
            }
        }
        $this->touchLog($logId, count($rawRows), count($rawRows));

        return [
            'fetched' => count($rawRows), 'inserted' => count($rawRows), 'updated' => 0,
            'maxSourceKey' => null, 'pages' => $pages,
        ];
    }

    private function pullRows(array $entity, string $field, ?string $filter, int $logId): array
    {
        $client = $this->client();
        $fetched = 0;
        $insertedCount = 0;
        $updatedCount = 0;
        $maxKey = null;
        $pages = 0;
        $rawBuffer = [];
        $typedBuffer = [];
        $select = $entity['select'] ?? null;
        $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;

        foreach ($client->streamEntity($entity['entity'], select: $select, filter: $filter, orderBy: $field.' asc', top: self::PAGE_SIZE, maxPages: self::MAX_PAGES) as $row) {
            $staging = $this->toStaging($row, [$field]);
            $rawBuffer[] = $staging;
            if ($typedMeta !== null) {
                $typedBuffer[] = $this->toTyped($row, $typedMeta['map'], $staging['source_key']);
            }
            $fetched++;

            $value = $row[$field] ?? null;
            if (is_numeric($value)) {
                $maxKey = $maxKey === null ? $value : max($maxKey, $value);
            }

            if (count($rawBuffer) >= self::PAGE_SIZE) {
                $stored = $this->flushBothUpsert($entity, $rawBuffer, $typedBuffer);
                $insertedCount += $stored['inserted'];
                $updatedCount += $stored['updated'];
                $rawBuffer = [];
                $typedBuffer = [];
                $pages++;
            }
        }

        if (count($rawBuffer) > 0) {
            $stored = $this->flushBothUpsert($entity, $rawBuffer, $typedBuffer);
            $insertedCount += $stored['inserted'];
            $updatedCount += $stored['updated'];
            $pages++;
        }

        if ($maxKey !== null) {
            DB::table('d365_sync_log')->where('id', $logId)->update(['max_source_key' => (string) $maxKey]);
        }

        return [
            'fetched' => $fetched, 'inserted' => $insertedCount, 'updated' => $updatedCount,
            'maxSourceKey' => $maxKey !== null ? (string) $maxKey : null, 'pages' => $pages,
        ];
    }

    /** Normalisasi satu baris OData menjadi row tabel staging (buang kolom @odata, duplikat, dsb.). */
    private function toStaging(array $row, array $keyFields): array
    {
        $payload = [];
        foreach ($row as $field => $value) {
            if (str_starts_with((string) $field, '@')) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $payload[$field] = $value;
        }

        $key = $this->buildKey($row, $keyFields);

        return [
            'source_key' => $key,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /**
     * Mapping satu baris OData ke row tabel tampungan bertipe (d365s_*).
     * Dijalankan bersamaan dengan toStaging() agar tidak menarik dua kali.
     */
    private function toTyped(array $row, array $fieldMap, string $sourceKey): array
    {
        $typed = ['source_key' => $sourceKey, 'created_at' => now(), 'updated_at' => now()];
        foreach ($fieldMap as $odataField => $dbColumn) {
            $value = $row[$odataField] ?? null;
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if (is_string($value) && $value === '') {
                $value = null;
            }
            $typed[$dbColumn] = $value;
        }

        return $typed;
    }

    /**
     * Flush buffer ke KEDUA tabel (raw d365_* dan typed d365s_*) sekaligus.
     * Typed row di-upsert supaya idempoten dengan raw.
     */
    private function flushBothUpsert(array $entity, array $stagingRows, array $typedRows): array
    {
        // --- raw tabel (payload JSON) ---
        $inserted = 0;
        $updated = 0;
        foreach (array_chunk($stagingRows, 500) as $chunk) {
            $keys = collect($chunk)->pluck('source_key')->all();
            $before = DB::table($entity['table'])->whereIn('source_key', $keys)->count();
            DB::table($entity['table'])->upsert($chunk, ['source_key'], ['payload', 'updated_at']);
            $chunkInserted = max(0, DB::table($entity['table'])->whereIn('source_key', $keys)->count() - $before);
            $inserted += $chunkInserted;
            $updated += count($chunk) - $chunkInserted;
        }

        // --- typed tabel (d365s_*) ---
        $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;
        if ($typedMeta !== null && count($typedRows) > 0) {
            foreach (array_chunk($typedRows, 500) as $chunk) {
                DB::table($typedMeta['table'])->upsert(
                    $chunk,
                    ['source_key'],
                    array_values(array_diff(array_values($typedMeta['map']), ['source_key'])) + ['updated_at'],
                );
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    private function buildKey(array $row, array $keyFields): string
    {
        $parts = [];
        foreach ($keyFields as $field) {
            $value = $row[$field] ?? '';
            $parts[] = is_scalar($value) ? (string) $value : '';
        }

        return mb_substr(implode('-', $parts), 0, 64);
    }

    /** Upsert saat incremental: insert baru atau update payload bila sudah ada. */
    private function flushUpsert(array $entity, array $stagingRows): array
    {
        $inserted = 0;
        $updated = 0;
        foreach (array_chunk($stagingRows, 500) as $chunk) {
            $keys = collect($chunk)->pluck('source_key')->all();
            $before = DB::table($entity['table'])->whereIn('source_key', $keys)->count();
            DB::table($entity['table'])->upsert($chunk, ['source_key'], ['payload', 'updated_at']);
            $chunkInserted = max(0, DB::table($entity['table'])->whereIn('source_key', $keys)->count() - $before);
            $inserted += $chunkInserted;
            // Baris yang tidak baru dianggap di-update (upsert menyentuh payload-nya).
            $updated += count($chunk) - $chunkInserted;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /** Snapshot: hapus isi lama lalu tulis ulang (master data relatif kecil). */
    private function flushSnapshot(array $entity, array $stagingRows): void
    {
        DB::table($entity['table'])->whereIn('source_key', collect($stagingRows)->pluck('source_key'))->delete();
        DB::table($entity['table'])->insertOrIgnore($stagingRows);
    }

    /** Kursor incremental: max SourceKey dari sync sukses terakhir. */
    private function lastMaxKey(array $entity): ?string
    {
        $last = DB::table('d365_sync_log')
            ->where('entity_key', $entity['key'])->where('status', 'success')
            ->orderByDesc('id')->value('max_source_key');

        return $last !== null && ctype_digit($last) ? $last : null;
    }

    private function openLog(array $entity): int
    {
        return (int) DB::table('d365_sync_log')->insertGetId([
            'entity_key' => $entity['key'], 'entity_name' => $entity['entity'],
            'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function touchLog(int $logId, int $addPages = 0, int $addFetched = 0, int $addInserted = 0): void
    {
        DB::table('d365_sync_log')->where('id', $logId)->where('status', 'running')->update([
            'pages' => DB::raw('pages + '.(int) $addPages),
            'fetched' => DB::raw('fetched + '.(int) $addFetched),
            'inserted' => DB::raw('inserted + '.(int) $addInserted),
            'updated_at' => now(),
        ]);
    }

    private function closeLog(int $logId, string $status, array $result, ?string $message = null): void
    {
        DB::table('d365_sync_log')->where('id', $logId)->update([
            'status' => $status, 'message' => $message,
            'fetched' => (int) ($result['fetched'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'pages' => (int) ($result['pages'] ?? 0),
            'max_source_key' => $result['maxSourceKey'] ?? null,
            'updated_at' => now(),
        ]);
    }

    /** Status terakhir tiap entity, untuk tampilan UI. */
    public function latestStatus(): Collection
    {
        return collect(self::ENTITIES)->map(static function (array $entity) {
            $last = DB::table('d365_sync_log')->where('entity_key', $entity['key'])->orderByDesc('id')->first();
            $count = DB::table($entity['table'])->count();
            $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;
            $typedCount = $typedMeta !== null ? DB::table($typedMeta['table'])->count() : null;

            return $entity + ['last' => $last, 'staging_count' => $count, 'typed_count' => $typedCount, 'typed_table' => $typedMeta['table'] ?? null];
        });
    }
}
