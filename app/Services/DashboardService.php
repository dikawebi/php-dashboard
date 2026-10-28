<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function all(?array $range = null): array
    {
        $costMatrix = $this->costMatrix($range);
        $production = $this->productionSummary($range);
        $inventory = $this->inventoryValuation($costMatrix, $production, $range);
        $costPerMt = $this->costPerMt($costMatrix, $production);

        return [
            'costMatrix' => $costMatrix,
            'productionSummary' => $production,
            'inventoryValuation' => $inventory,
            'costPerMT' => $costPerMt,
            'financialStatements' => $this->financialStatements($costMatrix, $inventory, $range),
            'config' => $this->settings(),
            'period' => $range !== null ? ['view' => $range['view'], 'year' => $range['year'], 'month' => $range['month'], 'label' => $this->periodLabel($range)] : $this->periodFallback(),
        ];
    }

    private function periodLabel(?array $range): string
    {
        if ($range === null) {
            return 'Oktober 2026';
        }
        $label = $this->monthName((int) ($range['month'] ?? 1)).' '.($range['year'] ?? now()->year);

        return ($range['view'] ?? 'mtd') === 'all' ? $label : $label.' ('.strtoupper((string) ($range['view'] ?? 'MTD')).')';
    }

    private function asOfLabel(?array $range): string
    {
        if ($range === null) {
            return '27 Oktober 2026';
        }
        $end = filled($range['end'] ?? null) ? (string) $range['end'] : now()->format('Y-m-d');

        return Carbon::parse($end)->day.' '.$this->monthName((int) Carbon::parse($end)->month).' '.Carbon::parse($end)->year;
    }

    /** Bentuk periode bila tanpa filter (kompatibilitas lama). */
    private function periodFallback(): array
    {
        $config = $this->settings();
        $year = (int) ($config['system']['periodYear'] ?? now()->year);
        $month = (int) ($config['system']['periodMonth'] ?? now()->month);

        return ['view' => 'all', 'year' => $year, 'month' => $month, 'label' => 'Semua Periode ('.$this->monthName($month).' '.$year.')'];
    }

    private function monthName(int $month): string
    {
        return ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$month - 1] ?? '?';
    }

    /** Tambahkan kondisi tanggal (start <= kolom <= end|null) ke query. */
    private function rangeQuery($query, ?array $range, string $column)
    {
        $start = $range['start'] ?? null;
        if ($start !== null && $start !== '') {
            $query->where($column, '>=', $start);
            $end = $range['end'] ?? null;
            if ($end !== null && $end !== '') {
                $query->where($column, '<=', $end);
            }
        }

        return $query;
    }

    public function settings(): array
    {
        $settings = DB::table('dashboard_settings')->where('setting_key', 'config')->value('setting_value');
        $stored = $settings ? json_decode($settings, true) : [];

        return array_replace_recursive($this->defaultSettings(), is_array($stored) ? $stored : []);
    }

    public function defaultSettings(): array
    {
        return [
            'system' => [
                'companyName' => 'PT Coal Mining & Resources', 'currency' => 'IDR',
                'currencyUnit' => 'Thousands (Ribuan IDR)', 'periodYear' => 2026, 'periodMonth' => 10,
            ],
            'branding' => ['logoPath' => 'images/logo-bp.svg'],
            'dashboardSetup' => [
                'general' => ['kgPerMT' => 1000],
                'production' => ['romFallbackRatio' => 0.45, 'haulingFallbackRatio' => 0.85, 'washingFallbackRatio' => 0.35, 'portFallbackRatio' => 0.40],
                'costPerMT' => ['haulingVolumeRatio' => 0.85, 'washingVolumeRatio' => 0.35, 'portVolumeRatio' => 0.40],
                'inventory' => [
                    'miningDirectCostPerMT' => 350000, 'romTransferRatio' => 0.92, 'haulTransferRatio' => 0.95,
                    'washingFeedRatio' => 0.60, 'washingYieldRatio' => 0.85, 'washingOutflowRatio' => 0.90,
                    'portDirectHaulRatio' => 0.40, 'portShipmentRatio' => 0.94,
                ],
                'financial' => [
                    'sellingPricePerMT' => 920000, 'cashAndBank' => 18450000, 'tradeReceivables' => 14200000,
                    'prepaidExpensesAndTaxes' => 3820000, 'fixedAssetsHeavyEquipment' => 68500000,
                    'miningPropertiesAndInfrastructure' => 42100000, 'accumulatedDepreciation' => -14500000,
                    'tradePayablesVendors' => 12450000, 'accruedOperatingExpenses' => 4890000,
                    'shortTermLeaseObligations' => 3200000, 'longTermLeaseFinancing' => 24500000,
                    'mineClosureProvisionARO' => 8900000, 'shareCapital' => 60000000,
                    'retainedEarnings' => 55000000, 'financeAccountPrefix' => '700100', 'forexAccountPrefix' => '700200',
                ],
            ],
        ];
    }

    public function saveSettings(array $settings): void
    {
        DB::table('dashboard_settings')->updateOrInsert(
            ['setting_key' => 'config'],
            ['setting_value' => json_encode(array_replace_recursive($this->defaultSettings(), $settings), JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function costMatrix(?array $range = null): array
    {
        $definitions = DB::table('cost_matrix_definitions')->orderBy('id')->get();
        $entries = $this->rangeQuery(DB::table('gl_entries'), $range, 'entry_date')->get();
        $aggregate = [];

        foreach ($entries as $entry) {
            $code = trim((string) $entry->main_account);
            if ($code === '') {
                continue;
            }

            $aggregate[$code] ??= [
                'code' => $code,
                'name' => (string) ($entry->account_name ?? ''),
                'valMining' => 0, 'valHauling' => 0, 'valWP' => 0, 'valPort' => 0,
                'costMining' => 0, 'costHauling' => 0, 'costWP' => 0,
                'costPort' => 0, 'costGA' => 0, 'costSelling' => 0,
            ];

            $amount = (float) $entry->amount_idr;
            $valuation = strtolower(trim((string) ($entry->valuation_to ?? '')));
            $costing = strtolower(trim((string) ($entry->costing_to ?? '')));

            if (str_contains($valuation, 'mining') || str_contains($valuation, 'pit')) {
                $aggregate[$code]['valMining'] += $amount;
            } elseif (str_contains($valuation, 'haul')) {
                $aggregate[$code]['valHauling'] += $amount;
            } elseif (str_contains($valuation, 'wash') || str_contains($valuation, 'wp')) {
                $aggregate[$code]['valWP'] += $amount;
            } elseif (str_contains($valuation, 'port') || str_contains($valuation, 'jetty')) {
                $aggregate[$code]['valPort'] += $amount;
            }

            if (str_contains($costing, 'mining') || str_contains($costing, 'pit')) {
                $aggregate[$code]['costMining'] += $amount;
            } elseif (str_contains($costing, 'haul')) {
                $aggregate[$code]['costHauling'] += $amount;
            } elseif (str_contains($costing, 'wash') || str_contains($costing, 'wp')) {
                $aggregate[$code]['costWP'] += $amount;
            } elseif (str_contains($costing, 'port') || str_contains($costing, 'jetty')) {
                $aggregate[$code]['costPort'] += $amount;
            } elseif ($costing === 'ga' || str_contains($costing, 'admin') || str_contains($costing, 'overhead')) {
                $aggregate[$code]['costGA'] += $amount;
            } elseif (str_contains($costing, 'sell') || str_contains($costing, 'market') || str_contains($costing, 'mkt')) {
                $aggregate[$code]['costSelling'] += $amount;
            }
        }

        $hasGl = $entries->isNotEmpty();
        $mapped = [];
        $rows = [];

        foreach ($definitions as $item) {
            $code = (string) $item->code;
            $mapped[$code] = true;
            if ((bool) $item->is_parent) {
                $rows[] = $this->emptyRow($code, (string) $item->name, true, null);

                continue;
            }

            $live = $aggregate[$code] ?? null;
            $row = [
                'code' => $code,
                'name' => $live['name'] ?? (string) $item->name,
                'parent' => $item->parent_code,
                'isParent' => false,
                'valMining' => $live['valMining'] ?? ($hasGl ? 0 : (float) $item->val_mining),
                'valHauling' => $live['valHauling'] ?? ($hasGl ? 0 : (float) $item->val_hauling),
                'valWP' => $live['valWP'] ?? ($hasGl ? 0 : (float) $item->val_wp),
                'valPort' => $live['valPort'] ?? ($hasGl ? 0 : (float) $item->val_port),
                'costMining' => $live['costMining'] ?? ($hasGl ? 0 : (float) $item->cost_mining),
                'costHauling' => $live['costHauling'] ?? ($hasGl ? 0 : (float) $item->cost_hauling),
                'costWP' => $live['costWP'] ?? ($hasGl ? 0 : (float) $item->cost_wp),
                'costPort' => $live['costPort'] ?? ($hasGl ? 0 : (float) $item->cost_port),
                'costGA' => $live['costGA'] ?? ($hasGl ? 0 : (float) $item->cost_ga),
                'costSelling' => $live['costSelling'] ?? ($hasGl ? 0 : (float) $item->cost_selling),
            ];
            $rows[] = $this->completeRow($row);
        }

        foreach ($aggregate as $code => $live) {
            if (isset($mapped[$code])) {
                continue;
            }
            $rows[] = $this->completeRow([
                'code' => $code,
                'name' => $live['name'] ?: "Account {$code}",
                'parent' => null,
                'isParent' => false,
                ...$live,
            ]);
        }

        foreach ($rows as &$row) {
            if (! $row['isParent']) {
                continue;
            }
            $children = array_filter($rows, fn (array $child) => $child['parent'] === $row['code']);
            foreach (['valMining', 'valHauling', 'valWP', 'valPort', 'costMining', 'costHauling', 'costWP', 'costPort', 'costGA', 'costSelling'] as $field) {
                $row[$field] = array_sum(array_column($children, $field));
            }
            $row = $this->completeRow($row);
        }
        unset($row);

        $leaves = array_filter($rows, fn (array $row) => ! $row['isParent']);
        $grandTotal = ['allCost' => 0, 'totalValuation' => 0, 'totalCosting' => 0];
        foreach (['valMining', 'valHauling', 'valWP', 'valPort', 'costMining', 'costHauling', 'costWP', 'costPort', 'costGA', 'costSelling'] as $field) {
            $grandTotal[$field] = array_sum(array_column($leaves, $field));
        }
        $grandTotal['totalValuation'] = $grandTotal['valMining'] + $grandTotal['valHauling'] + $grandTotal['valWP'] + $grandTotal['valPort'];
        $grandTotal['totalCosting'] = $grandTotal['costMining'] + $grandTotal['costHauling'] + $grandTotal['costWP'] + $grandTotal['costPort'] + $grandTotal['costGA'] + $grandTotal['costSelling'];
        $grandTotal['allCost'] = $grandTotal['totalValuation'] + $grandTotal['totalCosting'];

        return ['rows' => $rows, 'grandTotal' => $grandTotal];
    }

    private function emptyRow(string $code, string $name, bool $parent, ?string $parentCode): array
    {
        return $this->completeRow([
            'code' => $code, 'name' => $name, 'parent' => $parentCode, 'isParent' => $parent,
            'valMining' => 0, 'valHauling' => 0, 'valWP' => 0, 'valPort' => 0,
            'costMining' => 0, 'costHauling' => 0, 'costWP' => 0, 'costPort' => 0,
            'costGA' => 0, 'costSelling' => 0,
        ]);
    }

    private function completeRow(array $row): array
    {
        $row['totalValuation'] = $row['valMining'] + $row['valHauling'] + $row['valWP'] + $row['valPort'];
        $row['totalCosting'] = $row['costMining'] + $row['costHauling'] + $row['costWP'] + $row['costPort'] + $row['costGA'] + $row['costSelling'];
        $row['allCost'] = $row['totalValuation'] + $row['totalCosting'];
        $row['cek'] = 0;

        return $row;
    }

    public function productionSummary(?array $range = null): array
    {
        $tickets = $this->rangeQuery(DB::table('production_tickets')->orderBy('id'), $range, 'exit_date')->get();
        $kgPerMt = max(1, (float) ($this->settings()['dashboardSetup']['general']['kgPerMT'] ?? 1000));
        $totalGross = $totalTare = $totalNet = 0;
        $byProduct = $byWeighbridge = $daily = [];

        foreach ($tickets as $ticket) {
            $gross = (float) $ticket->gross_kg;
            $tare = (float) $ticket->tare_kg;
            $net = (float) $ticket->net_kg ?: $gross - $tare;
            $mt = $net / $kgPerMt;
            $product = $ticket->product ?: 'General Coal';
            $bridge = $ticket->weighbridge ?: 'WB Main';
            $date = $ticket->exit_date ?: 'Unknown';

            $totalGross += $gross;
            $totalTare += $tare;
            $totalNet += $net;
            $byProduct[$product] ??= ['count' => 0, 'nettoKg' => 0, 'nettoMT' => 0];
            $byProduct[$product]['count']++;
            $byProduct[$product]['nettoKg'] += $net;
            $byProduct[$product]['nettoMT'] += $mt;
            $byWeighbridge[$bridge] ??= ['count' => 0, 'nettoKg' => 0, 'nettoMT' => 0];
            $byWeighbridge[$bridge]['count']++;
            $byWeighbridge[$bridge]['nettoKg'] += $net;
            $byWeighbridge[$bridge]['nettoMT'] += $mt;
            $daily[$date] ??= ['date' => $date, 'count' => 0, 'nettoMT' => 0];
            $daily[$date]['count']++;
            $daily[$date]['nettoMT'] += $mt;
        }

        $recent = $tickets->slice(-15)->reverse()->values()->map(fn ($ticket) => [
            'Tgl Keluar' => $ticket->exit_date,
            'Jam Keluar' => $ticket->exit_time,
            'Product' => $ticket->product,
            'No Pol' => $ticket->truck_number,
            'Sopir' => $ticket->driver,
            'BRUTO' => (float) $ticket->gross_kg,
            'TARA' => (float) $ticket->tare_kg,
            'NETTO' => (float) $ticket->net_kg,
            'WEIGHBRIDGE' => $ticket->weighbridge,
            'Operator' => $ticket->operator,
        ])->all();

        $trends = array_values($daily);
        usort($trends, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return [
            'totalTickets' => $tickets->count(), 'totalBrutoKg' => $totalGross,
            'totalTaraKg' => $totalTare, 'totalNettoKg' => $totalNet,
            'totalNettoMT' => $totalNet / $kgPerMt, 'byProduct' => $byProduct,
            'byWeighbridge' => $byWeighbridge, 'dailyTrends' => $trends,
            'recentTickets' => $recent,
        ];
    }

    public function inventoryValuation(?array $costReport = null, ?array $production = null, ?array $range = null): array
    {
        $costReport ??= $this->costMatrix();
        $production ??= $this->productionSummary();
        $config = $this->settings();
        $setup = $config['dashboardSetup'];
        $stagesSetup = $config['inventorySetup']['stages'] ?? [
            'mining' => ['name' => 'ROM Coal (Stockpile Pit)', 'beginningQtyMT' => 25000, 'beginningValueIDR' => 9500000000],
            'hauling' => ['name' => 'Intermediate Coal (Stockpile BUA)', 'beginningQtyMT' => 18000, 'beginningValueIDR' => 8820000000],
            'washing' => ['name' => 'Washed Coal (Stockpile WP)', 'beginningQtyMT' => 12000, 'beginningValueIDR' => 6960000000],
            'port' => ['name' => 'Port Stockpile (BP PORT)', 'beginningQtyMT' => 35000, 'beginningValueIDR' => 23450000000],
        ];
        $gt = $costReport['grandTotal'];
        $valMining = $gt['valMining'] * 1000;
        $valHauling = $gt['valHauling'] * 1000;
        $valWp = $gt['valWP'] * 1000;
        $valPort = $gt['valPort'] * 1000;

        $kgPerMt = max(1, (float) $setup['general']['kgPerMT']);
        $romInflowQty = $production['byProduct']['ROM Coal']['nettoMT'] ?? ($production['totalNettoMT'] * $setup['production']['romFallbackRatio']);
        $rom = $this->movingStage($stagesSetup['mining'], $romInflowQty, $romInflowQty * $setup['inventory']['miningDirectCostPerMT'] + $valMining, $romInflowQty * $setup['inventory']['romTransferRatio']);
        $haulInflowQty = $rom['outflowQtyMT'];
        $haul = $this->movingStage($stagesSetup['hauling'], $haulInflowQty, $rom['outflowValueIDR'] + $valHauling, $haulInflowQty * $setup['inventory']['haulTransferRatio']);
        $wpFeed = $haul['outflowQtyMT'] * $setup['inventory']['washingFeedRatio'];
        $wpInflowQty = $wpFeed * $setup['inventory']['washingYieldRatio'];
        $wp = $this->movingStage($stagesSetup['washing'], $wpInflowQty, $wpFeed * $haul['movingAverageRateIDR'] + $valWp, $wpInflowQty * 0.90);
        $wp['outflowQtyMT'] = $wpInflowQty * $setup['inventory']['washingOutflowRatio'];
        $wp['outflowValueIDR'] = $wp['outflowQtyMT'] * $wp['movingAverageRateIDR'];
        $wp['endingQtyMT'] = $wp['beginningQtyMT'] ?? ($wp['inflowQtyMT'] + (float) $stagesSetup['washing']['beginningQtyMT']) - $wp['outflowQtyMT'];
        $wp['endingValueIDR'] = $wp['endingQtyMT'] * $wp['movingAverageRateIDR'];
        $portDirectQty = $haul['outflowQtyMT'] * $setup['inventory']['portDirectHaulRatio'];
        $portInflowQty = $wp['outflowQtyMT'] + $portDirectQty;
        $port = $this->movingStage($stagesSetup['port'], $portInflowQty, $wp['outflowValueIDR'] + $portDirectQty * $haul['movingAverageRateIDR'] + $valPort, $portInflowQty * $setup['inventory']['portShipmentRatio']);

        $stageData = [
            ['id' => 'mining', 'name' => $stagesSetup['mining']['name'], 'location' => 'Pit Stockpile A', 'setup' => $stagesSetup['mining'], 'calc' => $rom, 'valuation' => $valMining],
            ['id' => 'hauling', 'name' => $stagesSetup['hauling']['name'], 'location' => 'Stockpile BUA Intermediate', 'setup' => $stagesSetup['hauling'], 'calc' => $haul, 'valuation' => $valHauling],
            ['id' => 'washing', 'name' => $stagesSetup['washing']['name'], 'location' => 'Stockpile Washing Plant', 'setup' => $stagesSetup['washing'], 'calc' => $wp, 'valuation' => $valWp],
            ['id' => 'port', 'name' => $stagesSetup['port']['name'], 'location' => 'BP PORT / Jetty Stockpile', 'setup' => $stagesSetup['port'], 'calc' => $port, 'valuation' => $valPort],
        ];
        $stages = [];
        foreach ($stageData as $item) {
            $setup = $item['setup'];
            $calc = $item['calc'];
            $stages[] = [
                'id' => $item['id'], 'name' => $item['name'], 'location' => $item['location'],
                'beginningQtyMT' => (float) $setup['beginningQtyMT'],
                'beginningRateIDR' => $setup['beginningQtyMT'] > 0 ? $setup['beginningValueIDR'] / $setup['beginningQtyMT'] : 0,
                'beginningValueIDR' => (float) $setup['beginningValueIDR'],
                'inflowQtyMT' => $calc['inflowQtyMT'], 'inflowCostValuationIDR' => $item['valuation'],
                'inflowTotalValueIDR' => $calc['inflowValueIDR'], 'movingAverageRateIDR' => $calc['movingAverageRateIDR'],
                'outflowQtyMT' => $calc['outflowQtyMT'], 'outflowValueIDR' => $calc['outflowValueIDR'],
                'endingQtyMT' => $calc['endingQtyMT'], 'endingValueIDR' => $calc['endingValueIDR'],
            ];
        }
        $endingQty = array_sum(array_column($stages, 'endingQtyMT'));
        $endingValue = array_sum(array_column($stages, 'endingValueIDR'));

        return [
            'valuationMethod' => 'Moving Average', 'period' => $this->periodLabel($range), 'stages' => $stages,
            'totalEndingQtyMT' => $endingQty, 'totalEndingValueIDR' => $endingValue,
            'overallAvgRateIDR' => $endingQty > 0 ? $endingValue / $endingQty : 0,
            'cogsShippedValIDR' => $port['outflowValueIDR'], 'cogsShippedQtyMT' => $port['outflowQtyMT'],
        ];
    }

    private function movingStage(array $setup, float $inflowQty, float $inflowValue, float $outflowQty): array
    {
        $beginningQty = (float) $setup['beginningQtyMT'];
        $beginningValue = (float) $setup['beginningValueIDR'];
        $availableQty = $beginningQty + $inflowQty;
        $availableValue = $beginningValue + $inflowValue;
        $rate = $availableQty > 0 ? $availableValue / $availableQty : 0;
        $outflowValue = $outflowQty * $rate;

        return [
            'inflowQtyMT' => $inflowQty, 'inflowValueIDR' => $inflowValue,
            'movingAverageRateIDR' => $rate, 'outflowQtyMT' => $outflowQty,
            'outflowValueIDR' => $outflowValue, 'endingQtyMT' => $availableQty - $outflowQty,
            'endingValueIDR' => ($availableQty - $outflowQty) * $rate,
        ];
    }

    public function costPerMt(?array $costReport = null, ?array $production = null): array
    {
        $costReport ??= $this->costMatrix();
        $production ??= $this->productionSummary();
        $gt = $costReport['grandTotal'];
        $setup = $this->settings()['dashboardSetup'];
        $total = $production['totalNettoMT'] ?: 1;
        $miningMt = $production['byProduct']['ROM Coal']['nettoMT'] ?? $total * $setup['production']['romFallbackRatio'];
        $haulingMt = $total * $setup['costPerMT']['haulingVolumeRatio'];
        $washingMt = $production['byProduct']['Washed Coal']['nettoMT'] ?? $total * $setup['costPerMT']['washingVolumeRatio'];
        $portMt = $production['byProduct']['Barge Coal']['nettoMT'] ?? $total * $setup['costPerMT']['portVolumeRatio'];
        $stages = [
            ['stage' => 'Mining (Penambangan)', 'valuationCost' => $gt['valMining'], 'operatingCost' => $gt['costMining'], 'metricTons' => $miningMt],
            ['stage' => 'Coal Hauling (Pengangkutan)', 'valuationCost' => $gt['valHauling'], 'operatingCost' => $gt['costHauling'], 'metricTons' => $haulingMt],
            ['stage' => 'Washing Plant (Pencucian)', 'valuationCost' => $gt['valWP'], 'operatingCost' => $gt['costWP'], 'metricTons' => $washingMt],
            ['stage' => 'BP PORT & Jetty (Pelabuhan)', 'valuationCost' => $gt['valPort'], 'operatingCost' => $gt['costPort'], 'metricTons' => $portMt],
            ['stage' => 'General & Administrative (GA)', 'valuationCost' => 0, 'operatingCost' => $gt['costGA'], 'metricTons' => $total],
            ['stage' => 'Selling Expense (Pemasaran)', 'valuationCost' => 0, 'operatingCost' => $gt['costSelling'], 'metricTons' => $portMt],
        ];
        foreach ($stages as &$stage) {
            $stage['totalCost'] = $stage['valuationCost'] + $stage['operatingCost'];
            $stage['costPerMT'] = ($stage['totalCost'] * 1000) / max($stage['metricTons'], 1);
            $stage['costPerTotalMT'] = ($stage['totalCost'] * 1000) / $total;
        }
        unset($stage);

        $rows = array_filter($costReport['rows'], fn (array $row) => ! $row['isParent']);
        $group = fn (callable $filter) => array_sum(array_map(fn ($row) => $row['allCost'], array_filter($rows, $filter)));
        $costByAccountGroup = [
            ['group' => 'Fuel & Energy', 'amount' => $group(fn ($r) => $r['code'] === '5000601')],
            ['group' => 'Salary & Employee Benefits', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500005'))],
            ['group' => 'Equipment & Vehicle Rent', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500010'))],
            ['group' => 'Labour Outsourcing & Overheads', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500170') || $r['code'] === '5000402')],
            ['group' => 'Repair & Maintenance', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500080'))],
            ['group' => 'Freight & Handling', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500030'))],
            ['group' => 'Consultant & Professional Fee', 'amount' => $group(fn ($r) => str_starts_with($r['code'], '500050'))],
        ];

        return ['totalVolumeProducedMT' => $total, 'grandTotalCost' => $gt['allCost'], 'overallCostPerMT' => ($gt['allCost'] * 1000) / $total, 'stages' => $stages, 'costByAccountGroup' => $costByAccountGroup];
    }

    public function financialStatements(?array $costReport = null, ?array $inventory = null, ?array $range = null): array
    {
        $costReport ??= $this->costMatrix();
        $inventory ??= $this->inventoryValuation($costReport);
        $gt = $costReport['grandTotal'];
        $setup = $this->settings()['dashboardSetup']['financial'];
        $salesVolume = $inventory['cogsShippedQtyMT'] ?: 1200;
        $revenue = $salesVolume * $setup['sellingPricePerMT'] / 1000;
        $cogs = $inventory['cogsShippedValIDR'] / 1000;
        $grossProfit = $revenue - $cogs;
        $totalOpex = $gt['costGA'] + $gt['costSelling'];
        $ebitda = $grossProfit - $totalOpex;
        $rows = array_filter($costReport['rows'], fn (array $row) => ! $row['isParent']);
        $finance = array_sum(array_map(fn ($r) => $r['allCost'], array_filter($rows, fn ($r) => str_starts_with($r['code'], $setup['financeAccountPrefix']))));
        $forex = array_sum(array_map(fn ($r) => $r['allCost'], array_filter($rows, fn ($r) => str_starts_with($r['code'], $setup['forexAccountPrefix']))));
        $netProfit = $ebitda - $finance - $forex;
        $inventoryValue = $inventory['totalEndingValueIDR'] / 1000;
        $currentAssets = $setup['cashAndBank'] + $setup['tradeReceivables'] + $inventoryValue + $setup['prepaidExpensesAndTaxes'];
        $nonCurrentAssets = $setup['fixedAssetsHeavyEquipment'] + $setup['miningPropertiesAndInfrastructure'] + $setup['accumulatedDepreciation'];
        $currentLiabilities = $setup['tradePayablesVendors'] + $setup['accruedOperatingExpenses'] + $setup['shortTermLeaseObligations'];
        $nonCurrentLiabilities = $setup['longTermLeaseFinancing'] + $setup['mineClosureProvisionARO'];
        $totalLiabilities = $currentLiabilities + $nonCurrentLiabilities;
        $totalEquity = $setup['shareCapital'] + $setup['retainedEarnings'] + $netProfit;

        return [
            'incomeStatement' => [
                'period' => $this->periodLabel($range), 'salesVolumeMT' => $salesVolume,
                'revenue' => ['coalSalesRevenue' => $revenue, 'totalRevenue' => $revenue],
                'cogs' => ['productionCostAbsorbed' => $cogs, 'totalCOGS' => $cogs],
                'grossProfit' => $grossProfit, 'grossProfitMarginPct' => ($grossProfit / max($revenue, 1)) * 100,
                'operatingExpenses' => ['generalAdministrative' => $gt['costGA'], 'sellingExpense' => $gt['costSelling'], 'totalOpex' => $totalOpex],
                'operatingProfitEBITDA' => $ebitda, 'ebitdaMarginPct' => ($ebitda / max($revenue, 1)) * 100,
                'otherItems' => ['financeCost' => $finance, 'forexGainLoss' => $forex],
                'netProfitBeforeTax' => $netProfit, 'netProfitMarginPct' => ($netProfit / max($revenue, 1)) * 100,
            ],
            'balanceSheet' => [
                'asOfDate' => $this->asOfLabel($range),
                'assets' => ['currentAssets' => ['cashAndBank' => $setup['cashAndBank'], 'tradeReceivables' => $setup['tradeReceivables'], 'coalInventories' => ['totalInventory' => $inventoryValue], 'prepaidExpensesAndTaxes' => $setup['prepaidExpensesAndTaxes'], 'totalCurrentAssets' => $currentAssets], 'nonCurrentAssets' => ['fixedAssetsHeavyEquipment' => $setup['fixedAssetsHeavyEquipment'], 'miningPropertiesAndInfrastructure' => $setup['miningPropertiesAndInfrastructure'], 'accumulatedDepreciation' => $setup['accumulatedDepreciation'], 'totalNonCurrentAssets' => $nonCurrentAssets], 'totalAssets' => $currentAssets + $nonCurrentAssets],
                'liabilities' => ['currentLiabilities' => ['tradePayablesVendors' => $setup['tradePayablesVendors'], 'accruedOperatingExpenses' => $setup['accruedOperatingExpenses'], 'shortTermLeaseObligations' => $setup['shortTermLeaseObligations'], 'totalCurrentLiabilities' => $currentLiabilities], 'nonCurrentLiabilities' => ['longTermLeaseFinancing' => $setup['longTermLeaseFinancing'], 'mineClosureProvisionARO' => $setup['mineClosureProvisionARO'], 'totalNonCurrentLiabilities' => $nonCurrentLiabilities], 'totalLiabilities' => $totalLiabilities],
                'equity' => ['shareCapital' => $setup['shareCapital'], 'retainedEarnings' => $setup['retainedEarnings'], 'currentYearEarnings' => $netProfit, 'totalEquity' => $totalEquity],
            ],
        ];
    }
}
