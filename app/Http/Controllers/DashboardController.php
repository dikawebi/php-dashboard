<?php

namespace App\Http\Controllers;

use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly D365SyncService $d365,
    ) {}

    public function index(Request $request): Response
    {
        $period = $this->resolvePeriod($request);
        $data = $this->dashboard->all($period['range']);
        $data['activeTab'] = $request->string('tab', 'overview')->toString();
        $data['search'] = $request->string('search')->toString();
        $data['hideZero'] = $request->boolean('hide_zero');
        $data['parentsOnly'] = $request->boolean('parents_only');
        $data['collapsed'] = $request->input('collapsed', []);
        $data['d365Status'] = $this->d365->latestStatus();
        $data['period'] = $period['meta'];

        return response()->view('dashboard', $data);
    }

    /**
     * Kelola filter periode dari query string.
     * view: mtd (1 s/d akhir bulan terpilih), ytd (1 Jan s/d akhir bulan terpilih), all.
     * Default dari config (system.periodYear/periodMonth) atau bulan berjalan.
     */
    private function resolvePeriod(Request $request): array
    {
        $config = $this->dashboard->settings();
        $year = (int) ($request->input('period_year') ?: ($config['system']['periodYear'] ?? now()->year));
        $month = min(12, max(1, (int) ($request->input('period_month') ?: ($config['system']['periodMonth'] ?? now()->month))));
        $view = in_array($request->input('period_view'), ['mtd', 'ytd', 'all'], true) ? (string) $request->input('period_view') : 'mtd';

        $monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $label = $monthNames[$month - 1].' '.$year;
        $range = null;

        if ($view === 'mtd') {
            $range = ['view' => $view, 'year' => $year, 'month' => $month, 'start' => sprintf('%04d-%02d-01', $year, $month), 'end' => Carbon::create($year, $month, 1)->endOfMonth()->format('Y-m-d')];
            $label .= ' (MTD)';
        } elseif ($view === 'ytd') {
            $range = ['view' => $view, 'year' => $year, 'month' => $month, 'start' => sprintf('%04d-01-01', $year), 'end' => Carbon::create($year, $month, 1)->endOfMonth()->format('Y-m-d')];
            $label .= ' (YTD)';
        } else {
            $range = ['view' => 'all', 'year' => $year, 'month' => $month, 'start' => null, 'end' => null];
        }

        return ['view' => $view, 'year' => $year, 'month' => $month, 'label' => $label, 'range' => $range, 'meta' => ['view' => $view, 'year' => $year, 'month' => $month, 'label' => $label, 'start' => $range['start'], 'end' => $range['end']]];
    }

    public function saveInventory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mining_qty' => ['required', 'numeric', 'min:0'], 'mining_value' => ['required', 'numeric', 'min:0'],
            'hauling_qty' => ['required', 'numeric', 'min:0'], 'hauling_value' => ['required', 'numeric', 'min:0'],
            'washing_qty' => ['required', 'numeric', 'min:0'], 'washing_value' => ['required', 'numeric', 'min:0'],
            'port_qty' => ['required', 'numeric', 'min:0'], 'port_value' => ['required', 'numeric', 'min:0'],
        ]);
        $config = $this->dashboard->settings();
        $stages = [
            'mining' => ['name' => $config['inventorySetup']['stages']['mining']['name'] ?? 'ROM Coal (Stockpile Pit)', 'beginningQtyMT' => (float) $validated['mining_qty'], 'beginningValueIDR' => (float) $validated['mining_value']],
            'hauling' => ['name' => $config['inventorySetup']['stages']['hauling']['name'] ?? 'Intermediate Coal (Stockpile BUA)', 'beginningQtyMT' => (float) $validated['hauling_qty'], 'beginningValueIDR' => (float) $validated['hauling_value']],
            'washing' => ['name' => $config['inventorySetup']['stages']['washing']['name'] ?? 'Washed Coal (Stockpile WP)', 'beginningQtyMT' => (float) $validated['washing_qty'], 'beginningValueIDR' => (float) $validated['washing_value']],
            'port' => ['name' => $config['inventorySetup']['stages']['port']['name'] ?? 'Port Stockpile (BP PORT)', 'beginningQtyMT' => (float) $validated['port_qty'], 'beginningValueIDR' => (float) $validated['port_value']],
        ];
        foreach ($stages as &$stage) {
            $stage['beginningRateIDR'] = $stage['beginningQtyMT'] > 0 ? $stage['beginningValueIDR'] / $stage['beginningQtyMT'] : 0;
        }
        unset($stage);
        $config['inventorySetup']['stages'] = $stages;
        DB::table('dashboard_settings')->where('setting_key', 'config')->update(['setting_value' => json_encode($config), 'updated_at' => now()]);

        return back()->with('success', 'Setup saldo awal persediaan berhasil disimpan.');
    }

    public function exportMatrix(Request $request): Response|StreamedResponse
    {
        $period = $this->resolvePeriod($request);
        $matrix = $this->dashboard->costMatrix($period['range']);
        $headers = ['AccountCode', 'AccountName', 'Cek', 'ALL Cost', 'Valuation Mining', 'Valuation Coal Hauling', 'Valuation Washing Plant', 'Valuation BP PORT', 'Costing Mining', 'Costing Coal Hauling', 'Costing Washing Plant', 'Costing BP PORT', 'Costing GA', 'Costing Selling Expense'];
        $rows = [$headers];
        foreach ($matrix['rows'] as $row) {
            $rows[] = [$row['code'], $row['name'], 0, $row['allCost'], $row['valMining'], $row['valHauling'], $row['valWP'], $row['valPort'], $row['costMining'], $row['costHauling'], $row['costWP'], $row['costPort'], $row['costGA'], $row['costSelling']];
        }
        $gt = $matrix['grandTotal'];
        $rows[] = ['TOTAL', 'Grand Total', 0, $gt['allCost'], $gt['valMining'], $gt['valHauling'], $gt['valWP'], $gt['valPort'], $gt['costMining'], $gt['costHauling'], $gt['costWP'], $gt['costPort'], $gt['costGA'], $gt['costSelling']];

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'Cost_Report_Valuation_Costing_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importGl(Request $request): RedirectResponse
    {
        $request->validate(['gl_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt,tsv', 'max:20480']]);
        $file = $request->file('gl_file');
        $rows = $this->readSpreadsheet($file->getRealPath());
        if (count($rows) === 0) {
            return back()->withErrors(['gl_file' => 'Tidak ada baris GL yang dapat dibaca.']);
        }
        DB::table('gl_entries')->delete();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('gl_entries')->insert($chunk);
        }

        return back()->with('success', 'Berhasil mengimpor '.count($rows).' baris jurnal GL.');
    }

    private function readSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $grid = $sheet->toArray(null, true, true, false);
        if (count($grid) < 2) {
            return [];
        }
        $headers = array_map(fn ($header) => $this->standardHeader((string) $header), $grid[0]);
        $rows = [];
        foreach (array_slice($grid, 1) as $values) {
            $item = [];
            foreach ($headers as $index => $header) {
                $item[$header] = $values[$index] ?? null;
            }
            if (! empty($item['MainAccount'])) {
                $rows[] = [
                    'journal_number' => $item['Journal number'] ?? null, 'voucher' => $item['Voucher'] ?? null,
                    'entry_date' => isset($item['Date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $item['Date']) ? substr((string) $item['Date'], 0, 10) : null,
                    'account_name' => $item['AccountName'] ?? null, 'main_account' => (string) $item['MainAccount'],
                    'amount_idr' => $this->numeric($item['Amount IDR'] ?? $item['Amount'] ?? 0),
                    'valuation_to' => $item['Valuation to'] ?? null, 'costing_to' => $item['Costing to'] ?? null,
                    'source_payload' => json_encode($item), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }

        return $rows;
    }

    private function standardHeader(string $header): string
    {
        $key = strtolower(preg_replace('/[\s_-]+/', '', trim($header)));

        return match (true) {
            in_array($key, ['mainaccount', 'accountcode', 'accountno', 'kodeakun'], true) => 'MainAccount',
            in_array($key, ['accountname', 'namaakun'], true) => 'AccountName',
            in_array($key, ['amountidr', 'amount', 'nominal', 'jumlah'], true) => 'Amount IDR',
            str_starts_with($key, 'valuation') => 'Valuation to',
            str_starts_with($key, 'costing') => 'Costing to',
            default => trim($header),
        };
    }

    private function numeric(mixed $value): float
    {
        $value = trim((string) $value);
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '() ');
        $value = str_replace(['Rp', 'IDR', ' '], '', $value);
        $value = str_contains($value, ',') && str_contains($value, '.') && strrpos($value, ',') > strrpos($value, '.')
            ? str_replace(',', '.', str_replace('.', '', $value))
            : str_replace(',', '', $value);

        return ($negative ? -1 : 1) * (float) $value;
    }
}
