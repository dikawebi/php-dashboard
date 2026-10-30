<?php

namespace App\Http\Controllers;

use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman browser data tampungan hasil tarikan OData MSD (D365).
 * Menampilkan isi tabel d365s_* (typed staging) per entity dengan
 * pagination, filter tanggal, dan pencarian bebas.
 */
class D365DataController extends Controller
{
    /** Kolom output staging, mengikuti urutan header contoh_gl_import.csv. */
    private const STAGING_COLUMNS = [
        'journal_number' => 'Journal number',
        'voucher' => 'Voucher',
        'date' => 'Date',
        'year_closed' => 'Year closed',
        'account_name' => 'AccountName',
        'description' => 'Description',
        'currency' => 'Currency',
        'fuel_quantity' => 'Fuel quantity',
        'transaction_amount' => 'Amount in transaction currency',
        'amount_idr' => 'Amount IDR',
        'amount' => 'Amount',
        'reporting_amount' => 'Amount in reporting currency',
        'posting_type' => 'Posting type',
        'posting_layer' => 'Posting layer',
        'vendor_account' => 'Vendor account',
        'customer_account' => 'Customer account',
        'invoice_no' => 'Invoice no',
        'main_account' => 'MainAccount',
        'activity' => 'Activity',
        'cash_flow' => 'Cash_Flow',
        'department' => 'Departmen',
        'group_activity' => 'Group_Activity',
        'location' => 'Location',
        'project' => 'Project',
        'project_name' => 'Project name',
        'cost_center' => 'Cost center',
        'valuation_to' => 'Valuation to',
        'costing_to' => 'Costing to',
        'po_dt_number' => 'PO DT Number',
    ];

    /**
     * Konfigurasi tampilan per entity:
     * columns  = kolom tabel yang ditampilkan beserta label header
     * search   = kolom yang digunakan untuk pencarian teks
     * dateCol  = kolom tanggal untuk filter periode (nullable)
     * amtCols  = kolom angka yang diformat ribuan (untuk total footer)
     */
    private const ENTITY_VIEW = [
        'gl_entries' => [
            'table'   => 'd365s_gl_entries',
            'columns' => [
                'accounting_date'           => 'Tanggal',
                'journal_number'            => 'No. Jurnal',
                'voucher'                   => 'Voucher',
                'main_account'              => 'Akun',
                'ledger_account'            => 'Ledger Account',
                'posting_type'              => 'Tipe Posting',
                'currency_code'             => 'Mata Uang',
                'accounting_currency_amount' => 'Jumlah (IDR)',
                'transaction_currency_amount' => 'Jumlah Transaksi',
                'quantity'                  => 'Quantity',
                'project_id'                => 'Project',
                'dimension_values'          => 'Dimensi',
            ],
            'search'  => ['journal_number', 'voucher', 'main_account', 'ledger_account', 'project_id'],
            'dateCol' => 'accounting_date',
            'amtCols' => ['accounting_currency_amount', 'transaction_currency_amount', 'quantity'],
        ],
        'gl_journal_entries' => [
            'table'   => 'd365s_gl_journal_entries',
            'columns' => [
                'accounting_date'      => 'Tanggal',
                'journal_number'       => 'No. Jurnal',
                'voucher'              => 'Voucher',
                'journal_category'     => 'Kategori',
                'posting_layer'        => 'Layer',
                'fiscal_calendar_year' => 'Tahun Fiskal',
                'fiscal_calendar_period' => 'Periode Fiskal',
                'description'          => 'Keterangan',
            ],
            'search'  => ['journal_number', 'voucher', 'description'],
            'dateCol' => 'accounting_date',
            'amtCols' => [],
        ],
        'main_accounts' => [
            'table'   => 'd365s_main_accounts',
            'columns' => [
                'main_account_id'            => 'Kode Akun',
                'name'                       => 'Nama Akun',
                'type'                       => 'Tipe',
                'account_category_description' => 'Kategori',
                'chart_of_accounts'          => 'COA',
                'currency_code'              => 'Mata Uang',
                'reporting_type'             => 'Tipe Reporting',
                'debit_credit_proposal'      => 'D/C',
            ],
            'search'  => ['main_account_id', 'name', 'account_category_description'],
            'dateCol' => null,
            'amtCols' => [],
        ],
        'dimension_values' => [
            'table'   => 'd365s_dimension_values',
            'columns' => [
                'financial_dimension' => 'Dimensi',
                'legal_entity_id'     => 'Legal Entity',
                'dimension_value'     => 'Nilai Dimensi',
                'description'         => 'Keterangan',
                'active_from'         => 'Aktif Dari',
                'active_to'           => 'Aktif Sampai',
            ],
            'search'  => ['financial_dimension', 'dimension_value', 'description'],
            'dateCol' => 'active_from',
            'amtCols' => [],
        ],
        'ledger_journal_lines' => [
            'table'   => 'd365s_ledger_journal_lines',
            'columns' => [
                'trans_date'          => 'Tanggal',
                'journal_batch_number' => 'No. Batch Jurnal',
                'voucher'             => 'Voucher',
                'account'             => 'Akun',
                'account_display_value' => 'Nama Akun',
                'account_type'        => 'Tipe Akun',
                'amount_cur_debit'    => 'Debit',
                'amount_cur_credit'   => 'Kredit',
                'currency'            => 'Mata Uang',
                'txt'                 => 'Keterangan',
            ],
            'search'  => ['journal_batch_number', 'voucher', 'account', 'txt'],
            'dateCol' => 'trans_date',
            'amtCols' => ['amount_cur_debit', 'amount_cur_credit'],
        ],
        'ledger_entry_journals' => [
            'table'   => 'd365s_ledger_entry_journals',
            'columns' => [
                'posted_datetime'     => 'Tanggal Posting',
                'journal_batch_number' => 'No. Batch',
                'journal_name'        => 'Nama Jurnal',
                'journal_type'        => 'Tipe',
                'posted'              => 'Posted',
                'num_of_lines'        => 'Jml. Baris',
                'description'         => 'Keterangan',
            ],
            'search'  => ['journal_batch_number', 'journal_name', 'description'],
            'dateCol' => 'posted_datetime',
            'amtCols' => [],
        ],
        'vendors' => [
            'table'   => 'd365s_vendors',
            'columns' => [
                'vendor_account_number'    => 'Kode Vendor',
                'vendor_group_id'          => 'Grup',
                'vendor_organization_name' => 'Nama Vendor',
                'currency_code'            => 'Mata Uang',
                'payment_terms'            => 'Termin Bayar',
                'tax_group'                => 'Grup Pajak',
                'address_description'      => 'Alamat',
            ],
            'search'  => ['vendor_account_number', 'vendor_organization_name', 'vendor_group_id'],
            'dateCol' => null,
            'amtCols' => [],
        ],
        'customers' => [
            'table'   => 'd365s_customers',
            'columns' => [
                'customer_account_number' => 'Kode Customer',
                'customer_group_id'       => 'Grup',
                'organization_name'       => 'Nama Customer',
                'currency_code'           => 'Mata Uang',
                'payment_terms'           => 'Termin Bayar',
                'sales_currency_code'     => 'Mata Uang Jual',
                'address_description'     => 'Alamat',
            ],
            'search'  => ['customer_account_number', 'organization_name', 'customer_group_id'],
            'dateCol' => null,
            'amtCols' => [],
        ],
        'inventory_trans' => [
            'table'   => 'd365s_inventory_trans',
            'columns' => [
                'date_financial'       => 'Tanggal Finansial',
                'date_physical'        => 'Tanggal Fisik',
                'item_id'              => 'Kode Item',
                'invent_site_id'       => 'Site',
                'invent_location_id'   => 'Lokasi',
                'status_receipt'       => 'Status Terima',
                'status_issue'         => 'Status Keluar',
                'qty'                  => 'Qty',
                'cost_amount_posted'   => 'Biaya Posting',
                'cost_amount_physical' => 'Biaya Fisik',
                'voucher'              => 'Voucher',
            ],
            'search'  => ['item_id', 'invent_site_id', 'voucher'],
            'dateCol' => 'date_financial',
            'amtCols' => ['qty', 'cost_amount_posted', 'cost_amount_physical'],
        ],
    ];

    public function __construct(
        private readonly D365SyncService $sync,
        private readonly DashboardService $dashboard,
    ) {}

    /** Daftar semua entity (index). */
    public function index(): Response
    {
        $statuses = $this->sync->latestStatus();
        $config = $this->dashboard->settings();

        return response()->view('d365.index', [
            'statuses' => $statuses,
            'config'   => $config,
        ]);
    }

    /** Staging gabungan dengan format kolom import CSV contoh. */
    public function staging(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        $query = DB::table('d365s_gl_entries as gl')
            ->leftJoin('d365s_gl_journal_entries as h', 'h.source_key', '=', 'gl.general_journal_entry')
            ->leftJoin('d365s_main_accounts as a', 'a.source_key', '=', 'gl.main_account')
            ->select([
                'gl.*',
                'h.journal_number as header_journal_number',
                'h.voucher as header_voucher',
                'h.accounting_date as header_accounting_date',
                'h.posting_layer as header_posting_layer',
                'a.main_account_id as account_code',
                'a.name as account_name_lookup',
            ])
            ->orderByDesc('h.accounting_date')
            ->orderBy('gl.source_key');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('gl.description', 'like', '%'.$search.'%')
                    ->orWhere('gl.ledger_account', 'like', '%'.$search.'%')
                    ->orWhere('a.main_account_id', 'like', '%'.$search.'%')
                    ->orWhere('a.name', 'like', '%'.$search.'%');
            });
        }
        if ($dateFrom !== '') {
            $query->where('h.accounting_date', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('h.accounting_date', '<=', $dateTo);
        }

        $rows = $query->paginate(50)->withQueryString();
        $rows->setCollection($rows->getCollection()->map(function ($row): array {
            $dimensions = json_decode((string) ($row->dimension_values ?? ''), true);
            $dimensions = is_array($dimensions) ? (array) ($dimensions[0] ?? []) : [];

            return [
                'journal_number' => $row->header_journal_number,
                'voucher' => $row->header_voucher,
                'date' => $row->header_accounting_date,
                'year_closed' => 'No',
                'account_name' => $row->account_name_lookup ?: $row->account_code,
                'description' => $row->description,
                'currency' => $row->currency_code,
                'fuel_quantity' => $row->quantity,
                'transaction_amount' => $row->transaction_currency_amount,
                'amount_idr' => $row->currency_code === 'IDR' ? $row->transaction_currency_amount : $row->accounting_currency_amount,
                'amount' => $row->accounting_currency_amount,
                'reporting_amount' => $row->reporting_currency_amount,
                'posting_type' => $row->posting_type,
                'posting_layer' => $row->header_posting_layer,
                'vendor_account' => null,
                'customer_account' => null,
                'invoice_no' => null,
                'main_account' => $row->account_code ?: $row->main_account,
                'activity' => $dimensions['ACTIVITY'] ?? null,
                'cash_flow' => $dimensions['CASH_FLOW'] ?? null,
                'department' => $dimensions['DEPARTMENT'] ?? null,
                'group_activity' => $dimensions['GROUP_ACTIVITY'] ?? null,
                'location' => $dimensions['LOCATION'] ?? null,
                'project' => $row->project_id,
                'project_name' => null,
                'cost_center' => $dimensions['COST_CENTER'] ?? null,
                'valuation_to' => null,
                'costing_to' => null,
                'po_dt_number' => null,
            ];
        }));

        return response()->view('d365.staging', [
            'rows' => $rows,
            'columns' => self::STAGING_COLUMNS,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'config' => $this->dashboard->settings(),
        ]);
    }

    /** Export staging dengan format kolom import GL ke Excel 97-2003 (.xls). */
    public function exportStagingXls(Request $request): StreamedResponse
    {
        // PhpSpreadsheet keeps the XLS cell grid in memory; the web SAPI default
        // of 128 MB is not enough for a full staging export.
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $search = $request->string('search')->toString();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        $query = DB::table('d365s_gl_entries as gl')
            ->leftJoin('d365s_gl_journal_entries as h', 'h.source_key', '=', 'gl.general_journal_entry')
            ->leftJoin('d365s_main_accounts as a', 'a.source_key', '=', 'gl.main_account')
            ->select([
                'gl.*',
                'h.journal_number as header_journal_number',
                'h.voucher as header_voucher',
                'h.accounting_date as header_accounting_date',
                'h.posting_layer as header_posting_layer',
                'a.main_account_id as account_code',
                'a.name as account_name_lookup',
            ])
            ->orderByDesc('h.accounting_date')
            ->orderBy('gl.source_key');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('gl.description', 'like', '%'.$search.'%')
                    ->orWhere('gl.ledger_account', 'like', '%'.$search.'%')
                    ->orWhere('a.main_account_id', 'like', '%'.$search.'%')
                    ->orWhere('a.name', 'like', '%'.$search.'%');
            });
        }
        if ($dateFrom !== '') {
            $query->where('h.accounting_date', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('h.accounting_date', '<=', $dateTo);
        }

        $rows = $query->cursor()->map(function ($row): array {
            $dimensions = json_decode((string) ($row->dimension_values ?? ''), true);
            $dimensions = is_array($dimensions) ? (array) ($dimensions[0] ?? []) : [];

            return [
                'journal_number' => $row->header_journal_number,
                'voucher' => $row->header_voucher,
                'date' => $row->header_accounting_date,
                'year_closed' => 'No',
                'account_name' => $row->account_name_lookup ?: $row->account_code,
                'description' => $row->description,
                'currency' => $row->currency_code,
                'fuel_quantity' => $row->quantity,
                'transaction_amount' => $row->transaction_currency_amount,
                'amount_idr' => $row->currency_code === 'IDR' ? $row->transaction_currency_amount : $row->accounting_currency_amount,
                'amount' => $row->accounting_currency_amount,
                'reporting_amount' => $row->reporting_currency_amount,
                'posting_type' => $row->posting_type,
                'posting_layer' => $row->header_posting_layer,
                'vendor_account' => null,
                'customer_account' => null,
                'invoice_no' => null,
                'main_account' => $row->account_code ?: $row->main_account,
                'activity' => $dimensions['ACTIVITY'] ?? null,
                'cash_flow' => $dimensions['CASH_FLOW'] ?? null,
                'department' => $dimensions['DEPARTMENT'] ?? null,
                'group_activity' => $dimensions['GROUP_ACTIVITY'] ?? null,
                'location' => $dimensions['LOCATION'] ?? null,
                'project' => $row->project_id,
                'project_name' => null,
                'cost_center' => $dimensions['COST_CENTER'] ?? null,
                'valuation_to' => null,
                'costing_to' => null,
                'po_dt_number' => null,
            ];
        });

        $columns = self::STAGING_COLUMNS;

        return response()->streamDownload(function () use ($columns, $rows): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('GL Import Staging');
            $sheet->freezePane('A2');
            $sheet->fromArray(array_values($columns), null, 'A1');
            $sheet->getStyle('A1:AC1')->getFont()->setBold(true);
            $sheet->getStyle('A1:AC1')->getFill()->setFillType('solid')->getStartColor()->setARGB('1F2B3D');
            $sheet->getStyle('A1:AC1')->getFont()->getColor()->setARGB('FFFFFF');
            $sheet->setAutoFilter('A1:AC1');

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->fromArray(array_map(static fn ($key) => $row[$key] ?? null, array_keys($columns)), null, 'A'.$rowNumber++);
            }
            for ($columnIndex = 1; $columnIndex <= count($columns); $columnIndex++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(18);
            }

            (new Xls($spreadsheet))->save('php://output');
        }, 'd365_staging_import_gl_'.now()->format('Ymd_His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel',
        ]);
    }

    /** Ringkasan sum GL per Main Account dari typed staging D365. */
    public function mainAccountSummary(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        $query = DB::table('d365s_gl_entries as gl')
            ->leftJoin('d365s_main_accounts as a', 'a.source_key', '=', 'gl.main_account')
            ->selectRaw('COALESCE(a.main_account_id, gl.main_account) as account_code')
            ->selectRaw('COALESCE(a.name, "") as account_name')
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('SUM(COALESCE(gl.accounting_currency_amount, 0)) as all_cost')
            ->groupBy('a.main_account_id', 'a.name', 'gl.main_account')
            ->orderBy('account_code');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('gl.main_account', 'like', '%'.$search.'%')
                    ->orWhere('a.main_account_id', 'like', '%'.$search.'%')
                    ->orWhere('a.name', 'like', '%'.$search.'%');
            });
        }
        if ($dateFrom !== '') {
            $query->where('gl.accounting_date', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('gl.accounting_date', '<=', $dateTo);
        }

        $rows = $query->get()->map(static function ($row): array {
            return [
                'account_code' => (string) ($row->account_code ?? ''),
                'account_name' => (string) ($row->account_name ?: 'Account '.$row->account_code),
                'entry_count' => (int) $row->entry_count,
                'all_cost' => (float) $row->all_cost,
            ];
        });

        return response()->view('d365.main-account-summary', [
            'rows' => $rows,
            'totalRows' => $rows->sum('entry_count'),
            'grandTotal' => (float) $rows->sum('all_cost'),
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'config' => $this->dashboard->settings(),
        ]);
    }

    /** Browser data per entity — tabel tampungan d365s_*. */
    public function show(Request $request, string $entityKey): Response
    {
        $entityDef = $this->sync->entityByKey($entityKey);
        abort_unless($entityDef !== null, 404, 'Entity tidak ditemukan.');

        $viewConfig = self::ENTITY_VIEW[$entityKey] ?? null;
        abort_unless($viewConfig !== null, 404, 'Tampilan entity belum dikonfigurasi.');

        $table    = $viewConfig['table'];
        $columns  = $viewConfig['columns'];
        $search   = $request->string('search')->toString();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo   = $request->string('date_to')->toString();
        $perPage  = 50;

        $query = DB::table($table);

        // Filter teks bebas
        if ($search !== '') {
            $query->where(function ($q) use ($search, $viewConfig) {
                foreach ($viewConfig['search'] as $col) {
                    $q->orWhere($col, 'like', '%'.$search.'%');
                }
            });
        }

        // Filter tanggal
        if ($viewConfig['dateCol'] !== null) {
            if ($dateFrom !== '') {
                $query->where($viewConfig['dateCol'], '>=', $dateFrom);
            }
            if ($dateTo !== '') {
                $query->where($viewConfig['dateCol'], '<=', $dateTo);
            }
        }

        // Urutan default: kolom tanggal desc, lalu source_key
        if ($viewConfig['dateCol'] !== null) {
            $query->orderByDesc($viewConfig['dateCol']);
        }
        $query->orderBy('source_key');

        // Hitung total amount per kolom numerik (sebelum pagination)
        $amtTotals = [];
        foreach ($viewConfig['amtCols'] as $col) {
            $amtTotals[$col] = (float) (clone $query)->sum($col);
        }

        $rows = $query->paginate($perPage)->withQueryString();

        return response()->view('d365.show', [
            'entityKey'  => $entityKey,
            'entityDef'  => $entityDef,
            'viewConfig' => $viewConfig,
            'columns'    => $columns,
            'rows'       => $rows,
            'search'     => $search,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'amtTotals'  => $amtTotals,
            'config'     => $this->dashboard->settings(),
        ]);
    }
}
