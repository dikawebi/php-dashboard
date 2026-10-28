<?php

namespace App\Http\Controllers;

use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Halaman browser data tampungan hasil tarikan OData MSD (D365).
 * Menampilkan isi tabel d365s_* (typed staging) per entity dengan
 * pagination, filter tanggal, dan pencarian bebas.
 */
class D365DataController extends Controller
{
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
                'account_name'              => 'Nama Akun',
                'posting_type'              => 'Tipe Posting',
                'currency_code'             => 'Mata Uang',
                'accounting_currency_amount' => 'Jumlah (IDR)',
                'dimension_values'          => 'Dimensi',
            ],
            'search'  => ['journal_number', 'voucher', 'main_account', 'account_name'],
            'dateCol' => 'accounting_date',
            'amtCols' => ['accounting_currency_amount'],
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
        'po_headers' => [
            'table'   => 'd365s_po_headers',
            'columns' => [
                'accounting_date'              => 'Tanggal',
                'purchase_order_number'        => 'No. PO',
                'purchase_order_name'          => 'Nama PO',
                'order_vendor_account_number'  => 'Kode Vendor',
                'purchase_order_status'        => 'Status',
                'currency_code'                => 'Mata Uang',
                'payment_terms_name'           => 'Termin Bayar',
            ],
            'search'  => ['purchase_order_number', 'purchase_order_name', 'order_vendor_account_number'],
            'dateCol' => 'accounting_date',
            'amtCols' => [],
        ],
        'po_lines' => [
            'table'   => 'd365s_po_lines',
            'columns' => [
                'purchase_order_number'     => 'No. PO',
                'line_number'               => 'Baris',
                'item_number'               => 'Kode Item',
                'line_description'          => 'Keterangan',
                'ordered_purchase_quantity' => 'Qty PO',
                'purchase_unit_symbol'      => 'Satuan',
                'purchase_price'            => 'Harga',
                'line_amount'               => 'Jumlah',
            ],
            'search'  => ['purchase_order_number', 'item_number', 'line_description'],
            'dateCol' => null,
            'amtCols' => ['purchase_price', 'line_amount'],
        ],
        'vendor_invoices' => [
            'table'   => 'd365s_vendor_invoices',
            'columns' => [
                'invoice_date'        => 'Tanggal Invoice',
                'invoice'             => 'No. Invoice',
                'vendor_invoice_number' => 'No. Invoice Vendor',
                'invoice_account'     => 'Kode Vendor',
                'due_date'            => 'Jatuh Tempo',
                'invoice_amount'      => 'Jumlah',
                'currency'            => 'Mata Uang',
                'description'         => 'Keterangan',
            ],
            'search'  => ['invoice', 'vendor_invoice_number', 'invoice_account'],
            'dateCol' => 'invoice_date',
            'amtCols' => ['invoice_amount'],
        ],
        'vendor_invoice_lines' => [
            'table'   => 'd365s_vendor_invoice_lines',
            'columns' => [
                'invoice'         => 'No. Invoice',
                'invoice_line'    => 'Baris',
                'item_number'     => 'Kode Item',
                'line_description' => 'Keterangan',
                'quantity'        => 'Qty',
                'unit'            => 'Satuan',
                'unit_price'      => 'Harga',
                'line_amount'     => 'Jumlah',
                'main_account_id' => 'Akun GL',
            ],
            'search'  => ['invoice', 'item_number', 'line_description', 'main_account_id'],
            'dateCol' => null,
            'amtCols' => ['unit_price', 'line_amount'],
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
