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
                'SourceKey', 'SubledgerVoucherDataAreaId', 'GeneralJournalEntry', 'MainAccount', 'LedgerAccount',
                'TransactionCurrencyCode', 'AccountingCurrencyAmount', 'ReportingCurrencyAmount',
                'TransactionCurrencyAmount', 'Quantity', 'ProjId_SA', 'PostingType', 'Text',
                'LedgerDimension', 'LedgerDimensionValuesJson',
            ],
        ],
        [
            'key' => 'gl_journal_entries', 'label' => 'GL Journal Entries (Header GL)',
            'entity' => 'GeneralJournalEntryBiEntities', 'table' => 'd365_gl_journal_entries',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'SubledgerVoucherDataAreaId',
            'dateField' => 'AccountingDate',
            'select' => [
                'SourceKey', 'SubledgerVoucherDataAreaId', 'JournalNumber', 'SubledgerVoucher', 'DocumentNumber',
                'AccountingDate', 'DocumentDate', 'JournalCategory', 'PostingLayer',
                'FiscalCalendarYear', 'FiscalCalendarPeriod',
            ],
        ],
        [
            'key' => 'main_accounts', 'label' => 'Main Accounts (Chart of Accounts)',
            'entity' => 'MainAccountBiEntities', 'table' => 'd365_main_accounts',
            'mode' => 'snapshot', 'sourceKey' => 'SourceKey',
            'dataAreaField' => null, // Global Chart of Accounts (COA) shared across legal entities
            'select' => [
                'SourceKey', 'MainAccountId', 'Name', 'Type', 'LedgerChartOfAccounts',
                'CurrencyCode', 'ReportingAccountType', 'DebitCreditProposal',
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
            'dateField' => 'TransDate',
            'select' => [
                'SourceKey', 'dataAreaId', 'JournalNum', 'Voucher', 'DocumentDate', 'AccountType',
                'AmountCurDebit', 'AmountCurCredit',
                'CurrencyCode', 'Txt', 'Invoice', 'PaymReference', 'OffsetCompany',
            ],
        ],
        [
            'key' => 'ledger_entry_journals', 'label' => 'Ledger Entry Journals (Jurnal Aktif)',
            'entity' => 'LedgerEntryJournalBiEntities', 'table' => 'd365_ledger_entry_journals',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            // Field company dikembalikan kosong pada environment ini; jangan memfilter
            // entity berdasarkan field tersebut agar jurnal aktif tidak hilang.
            'dataAreaField' => null,
            'select' => [
                'SourceKey', 'LedgerJournalTableDataAreaId', 'JournalNumber',
            ],
        ],
        [
            'key' => 'vendors', 'label' => 'Vendors (Master Vendor)',
            'entity' => 'VendorsV2', 'table' => 'd365_vendors',
            'mode' => 'snapshot', 'sourceKey' => 'VendorAccountNumber',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'VendorAccountNumber', 'dataAreaId', 'VendorGroupId', 'VendorOrganizationName',
                'AddressDescription', 'CurrencyCode', 'SalesTaxGroupCode',
            ],
        ],
        [
            'key' => 'customers', 'label' => 'Customers (Master Customer)',
            'entity' => 'CustomersV3', 'table' => 'd365_customers',
            'mode' => 'snapshot', 'sourceKey' => 'CustomerAccount',
            'dataAreaField' => 'dataAreaId',
            'select' => [
                'CustomerAccount', 'dataAreaId', 'CustomerGroupId', 'OrganizationName',
                'AddressDescription', 'SalesTaxGroup',
            ],
        ],
        [
            'key' => 'inventory_trans', 'label' => 'Inventory Transactions (Invent Trans)',
            'entity' => 'InventTransBiEntities', 'table' => 'd365_inventory_trans',
            'mode' => 'incremental', 'sourceKey' => 'SourceKey',
            'dataAreaField' => 'dataAreaId',
            'dateField' => 'DateFinancial',
            'select' => [
                'SourceKey', 'dataAreaId', 'ItemId', 'inventDimId', 'StatusReceipt', 'StatusIssue',
                'Qty', 'CostAmountPosted', 'CostAmountAdjustment',
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
                'GeneralJournalEntry'        => 'general_journal_entry',
                'MainAccount'               => 'main_account',
                'LedgerAccount'             => 'ledger_account',
                'TransactionCurrencyCode'   => 'currency_code',
                'AccountingCurrencyAmount'  => 'accounting_currency_amount',
                'ReportingCurrencyAmount'   => 'reporting_currency_amount',
                'TransactionCurrencyAmount' => 'transaction_currency_amount',
                'Quantity'                  => 'quantity',
                'ProjId_SA'                 => 'project_id',
                'PostingType'               => 'posting_type',
                'Text'                      => 'description',
                'LedgerDimensionValuesJson' => 'dimension_values',
            ],
        ],
        'gl_journal_entries' => [
            'table' => 'd365s_gl_journal_entries',
            'map' => [
                'SubledgerVoucherDataAreaId' => 'company',
                'JournalNumber'              => 'journal_number',
                'SubledgerVoucher'           => 'voucher',
                'DocumentNumber'             => 'document_number',
                'AccountingDate'             => 'accounting_date',
                'DocumentDate'               => 'document_date',
                'JournalCategory'            => 'journal_category',
                'PostingLayer'               => 'posting_layer',
                'FiscalCalendarYear'         => 'fiscal_calendar_year',
                'FiscalCalendarPeriod'       => 'fiscal_calendar_period',
            ],
        ],
        'main_accounts' => [
            'table' => 'd365s_main_accounts',
            'map' => [
                'MainAccountId'              => 'main_account_id',
                'Name'                       => 'name',
                'Type'                       => 'type',
                'LedgerChartOfAccounts'      => 'chart_of_accounts',
                'CurrencyCode'               => 'currency_code',
                'ReportingAccountType'       => 'reporting_type',
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
                'JournalNum'          => 'journal_batch_number',
                'Voucher'             => 'voucher',
                'DocumentDate'        => 'trans_date',
                'AccountType'         => 'account_type',
                'AmountCurDebit'      => 'amount_cur_debit',
                'AmountCurCredit'     => 'amount_cur_credit',
                'CurrencyCode'        => 'currency',
                'Txt'                 => 'txt',
            ],
        ],
        'ledger_entry_journals' => [
            'table' => 'd365s_ledger_entry_journals',
            'map' => [
                'LedgerJournalTableDataAreaId' => 'company',
                'JournalNumber'      => 'journal_batch_number',
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
                'SalesTaxGroupCode'      => 'tax_group',
            ],
        ],
        'customers' => [
            'table' => 'd365s_customers',
            'map' => [
                'dataAreaId'            => 'company',
                'CustomerAccount'       => 'customer_account_number',
                'CustomerGroupId'       => 'customer_group_id',
                'OrganizationName'      => 'organization_name',
                'AddressDescription'    => 'address_description',
            ],
        ],
        'inventory_trans' => [
            'table' => 'd365s_inventory_trans',
            'map' => [
                'dataAreaId'           => 'company',
                'ItemId'               => 'item_id',
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
    private const PAGE_SIZE = 1000;

    /** Batas pengaman jumlah halaman per sync per entity. */
    private const MAX_PAGES = 2000;

    public function __construct(private readonly DashboardService $dashboard)
    {
        // OData GL dapat membawa payload besar; naikkan batas CLI worker
        // tanpa mengubah konfigurasi PHP global/server web.
        if (PHP_SAPI === 'cli') {
            ini_set('memory_limit', '512M');
        }
    }

    public function client(): D365ODataClient
    {
        return D365ODataClient::fromSettings($this->dashboard->settings());
    }

    public function entityByKey(string $key): ?array
    {
        return collect(self::ENTITIES)->first(fn ($entity) => $entity['key'] === $key);
    }

    /** Jalankan sync untuk satu entity; kembalikan ringkasan hasilnya. */
    public function syncEntity(array $entity, ?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $limit = $limit !== null ? max(1, $limit) : null;
        $parentKeys = $this->parentKeysFor($entity['key'], $dateFrom, $dateTo);
        if ($parentKeys !== null) {
            return $this->syncByParentKeys($entity, $parentKeys, $limit, $dateFrom, $dateTo);
        }
        $logId = $this->openLog($entity, $limit ?? $this->estimateTotal($entity, $dateFrom, $dateTo));

        try {
            $sourceKeyField = $entity['sourceKey'];
            $isScalarKey = is_string($sourceKeyField);
            $result = match ($entity['mode']) {
                'incremental' => $this->pullIncremental($entity, $logId, $limit, $dateFrom, $dateTo),
                'snapshot' => $isScalarKey
                    ? $this->pullSnapshot($entity, [$sourceKeyField], $logId, $limit, $dateFrom, $dateTo)
                    : $this->pullSnapshot($entity, $sourceKeyField, $logId, $limit, $dateFrom, $dateTo),
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

    /** Parent keys untuk child entity pada sync periode terbatas. */
    private function parentKeysFor(string $entityKey, ?string $dateFrom, ?string $dateTo): ?array
    {
        if (! filled($dateFrom) || ! filled($dateTo)) {
            return null;
        }

        return match ($entityKey) {
            'gl_entries' => DB::table('d365s_gl_journal_entries')->whereBetween('accounting_date', [$dateFrom, date('Y-m-d', strtotime($dateTo.' -1 day'))])->pluck('source_key')->map(fn ($v) => (string) $v)->all(),
            'ledger_entry_journals' => DB::table('d365s_ledger_journal_lines')->whereBetween('trans_date', [$dateFrom, date('Y-m-d', strtotime($dateTo.' -1 day'))])->pluck('journal_batch_number')->filter()->map(fn ($v) => (string) $v)->unique()->values()->all(),
            default => null,
        };
    }

    /** Tarik child entity memakai OR filter berbasis key parent. */
    private function syncByParentKeys(array $entity, array $parentKeys, ?int $limit, ?string $dateFrom, ?string $dateTo): array
    {
        $logId = $this->openLog($entity, null);
        $parentField = match ($entity['key']) {
            'gl_entries' => 'GeneralJournalEntry',
            'ledger_entry_journals' => 'JournalNumber',
            default => throw new D365Exception('Parent filter tidak dikonfigurasi untuk '.$entity['key']),
        };
        $rawBuffer = [];
        $typedBuffer = [];
        $fetched = 0;
        $inserted = 0;
        $updated = 0;
        $pages = 0;
        $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;
        if ($entity['mode'] === 'snapshot') {
            DB::table($entity['table'])->delete();
            if ($typedMeta) DB::table($typedMeta['table'])->delete();
        }

        foreach (array_chunk($parentKeys, 50) as $chunk) {
            $clauses = array_map(function ($value) use ($parentField): string {
                $value = (string) $value;
                return is_numeric($value) ? $parentField.' eq '.$value : $parentField." eq '".str_replace("'", "''", $value)."'";
            }, $chunk);
            $filter = $this->buildEntityFilter($entity, '('.implode(' or ', $clauses).')');
            foreach ($this->client()->streamEntity($entity['entity'], $entity['select'], $filter, $entity['mode'] === 'incremental' ? 'SourceKey asc' : null, self::PAGE_SIZE, self::MAX_PAGES) as $row) {
                $staging = $this->toStaging($row, is_array($entity['sourceKey']) ? $entity['sourceKey'] : [$entity['sourceKey']]);
                $rawBuffer[] = $staging;
                if ($typedMeta) $typedBuffer[] = $this->toTyped($row, $typedMeta['map'], $staging['source_key']);
                $fetched++;
                if ($limit !== null && $fetched >= $limit) break 2;
            }
            if (count($rawBuffer) > 0) {
                $stored = $this->flushBothUpsert($entity, $rawBuffer, $typedBuffer);
                $inserted += $stored['inserted']; $updated += $stored['updated']; $rawBuffer = []; $typedBuffer = []; $pages++;
                $this->touchProgress($logId, $pages, $fetched, $inserted, $updated);
            }
        }
        if (count($rawBuffer) > 0) {
            $stored = $this->flushBothUpsert($entity, $rawBuffer, $typedBuffer);
            $inserted += $stored['inserted']; $updated += $stored['updated']; $pages++;
        }
        $result = ['fetched' => $fetched, 'inserted' => $inserted, 'updated' => $updated, 'pages' => $pages, 'maxSourceKey' => null];
        $this->closeLog($logId, 'success', $result);
        return ['entity' => $entity, 'status' => 'success'] + $result;
    }

    /** Sync semua entity; kembalikan daftar ringkasan per entity. */
    public function syncAll(?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $results = [];
        foreach (self::ENTITIES as $entity) {
            try {
                $results[] = $this->syncEntity($entity, $limit, $dateFrom, $dateTo);
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

    private function buildEntityFilter(array $entity, ?string $additionalFilter = null, ?string $dateFrom = null, ?string $dateTo = null): ?string
    {
        $parts = [];

        $companyField = $entity['dataAreaField'] ?? null;
        if ($entity['key'] === 'dimension_values') {
            // Financial dimensions are shared/global as well as legal-entity-specific.
            // Keep every company except ARCS; blank LegalEntityId rows are retained.
            $parts[] = "LegalEntityId ne 'arcs'";
        } elseif (filled($companyField)) {
            // F&O returns legal-entity identifiers in lowercase (for example `bp`)
            // even when the admin setting is entered as `BP`.
            $company = strtolower($this->targetCompany());
            $parts[] = sprintf("%s eq '%s'", $companyField, $company);
        }

        if (filled($additionalFilter)) {
            $parts[] = "({$additionalFilter})";
        }

        if (filled($dateFrom) && filled($dateTo) && filled($entity['dateField'] ?? null)) {
            $dateField = $entity['dateField'];
            $parts[] = sprintf("%s ge %sT00:00:00Z and %s lt %sT00:00:00Z", $dateField, $dateFrom, $dateField, $dateTo);
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

    private function pullIncremental(array $entity, int $logId, ?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $field = (string) $entity['sourceKey'];
        $cursor = $this->lastMaxKey($entity);
        $incrementalFilter = $cursor !== null ? sprintf('%s gt %s', $field, $cursor) : null;
        $filter = $this->buildEntityFilter($entity, $incrementalFilter, $dateFrom, $dateTo);

        return $this->pullRows($entity, $field, $filter, $logId, $limit);
    }

    private function pullSnapshot(array $entity, array $keyFields, int $logId, ?int $limit = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $client = $this->client();
        $rawRows = [];
        $typedRows = [];
        $pages = 0;
        $filter = $this->buildEntityFilter($entity, null, $dateFrom, $dateTo);
        $select = $entity['select'] ?? null;
        $typedMeta = self::TYPED_TABLE_MAP[$entity['key']] ?? null;

        $top = $limit !== null ? min(self::PAGE_SIZE, $limit) : self::PAGE_SIZE;
        foreach ($client->streamEntity($entity['entity'], select: $select, filter: $filter, top: $top, maxPages: self::MAX_PAGES) as $row) {
            $staging = $this->toStaging($row, $keyFields);
            $rawRows[] = $staging;
            if ($typedMeta !== null) {
                $typedRows[] = $this->toTyped($row, $typedMeta['map'], $staging['source_key']);
            }
            if (count($rawRows) >= self::PAGE_SIZE) {
                $pages++;
                $this->touchProgress($logId, $pages, count($rawRows), 0, 0);
            }
            if ($limit !== null && count($rawRows) >= $limit) {
                break;
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

    private function pullRows(array $entity, string $field, ?string $filter, int $logId, ?int $limit = null): array
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

        $top = $limit !== null ? min(self::PAGE_SIZE, $limit) : self::PAGE_SIZE;
        foreach ($client->streamEntity($entity['entity'], select: $select, filter: $filter, orderBy: $field.' asc', top: $top, maxPages: self::MAX_PAGES) as $row) {
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
                $this->touchProgress($logId, $pages, $fetched, $insertedCount, $updatedCount);
            }
            if ($limit !== null && $fetched >= $limit) {
                break;
            }
        }

        if (count($rawBuffer) > 0) {
            $stored = $this->flushBothUpsert($entity, $rawBuffer, $typedBuffer);
            $insertedCount += $stored['inserted'];
            $updatedCount += $stored['updated'];
            $pages++;
            $this->touchProgress($logId, $pages, $fetched, $insertedCount, $updatedCount);
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
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1) {
                $value = str_ends_with($dbColumn, '_datetime') ? substr($value, 0, 19) : substr($value, 0, 10);
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

    private function estimateTotal(array $entity, ?string $dateFrom = null, ?string $dateTo = null): ?int
    {
        try {
            $filter = null;
            if ($entity['mode'] === 'incremental') {
                $cursor = $this->lastMaxKey($entity);
                $filter = $this->buildEntityFilter($entity, $cursor !== null
                    ? sprintf('%s gt %s', $entity['sourceKey'], $cursor)
                    : null, $dateFrom, $dateTo);
            } else {
                $filter = $this->buildEntityFilter($entity, null, $dateFrom, $dateTo);
            }

            $total = $this->client()->countEntity($entity['entity'], $filter);
            if ($total !== null) {
                return $total;
            }

            // Jangan melakukan scan penuh hanya untuk menghitung total. Pada
            // entity besar, fallback ini membuat job tertahan sebelum progress
            // log dibuat. Progress tetap menampilkan jumlah fetched dengan total
            // null sampai endpoint menyediakan @odata.count.
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function openLog(array $entity, ?int $total = null): int
    {
        return (int) DB::table('d365_sync_log')->insertGetId([
            'entity_key' => $entity['key'], 'entity_name' => $entity['entity'],
            'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
            'total' => $total, 'progress_percent' => 0,
        ]);
    }

    private function touchProgress(int $logId, int $pages, int $fetched, int $inserted, int $updated): void
    {
        $total = DB::table('d365_sync_log')->where('id', $logId)->value('total');
        $percent = $total !== null && (int) $total > 0
            ? min(99, (int) floor(($fetched / (int) $total) * 100))
            : 0;

        DB::table('d365_sync_log')->where('id', $logId)->where('status', 'running')->update([
            'pages' => $pages, 'fetched' => $fetched, 'inserted' => $inserted,
            'updated' => $updated, 'progress_percent' => $percent, 'updated_at' => now(),
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
            'status' => $status, 'message' => $message !== null ? mb_substr($message, 0, 60000) : null,
            'fetched' => (int) ($result['fetched'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'pages' => (int) ($result['pages'] ?? 0),
            'progress_percent' => $status === 'success' ? 100 : (int) ($result['progressPercent'] ?? 0),
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
