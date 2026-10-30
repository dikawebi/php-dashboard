<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Akuntansi & Biaya Pertambangan Batubara</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
@php
    $tabs = [
        'overview' => '📊 Ringkasan Eksekutif', 'matrix' => '📑 Report Cost (Valuation & Costing)',
        'cost' => '⚖️ Cost per Metric Ton', 'inventory' => '📦 Valuasi Persediaan (Moving Avg)',
        'financial' => '📑 Laporan Keuangan (FS)', 'integration' => '🔄 Integrasi & Sumber Data',
    ];
    $activeTab = array_key_exists($activeTab, $tabs) ? $activeTab : 'overview';
    $num = fn ($value) => round((float) $value) == 0 ? '-' : ((float) $value < 0 ? '(' . number_format(abs((float) $value), 0, ',', '.') . ')' : number_format((float) $value, 0, ',', '.'));
    $mt = fn ($value) => number_format((float) $value, 2, ',', '.') . ' MT';
    $gt = $costMatrix['grandTotal'];
    $inc = $financialStatements['incomeStatement'];
    $bs = $financialStatements['balanceSheet'];
    $canImportGl = in_array(auth()->user()->role, ['administrator', 'accountant'], true);
    $canEditInventory = in_array(auth()->user()->role, ['administrator', 'accountant', 'operations'], true);
@endphp
<body>
<header class="app-header">
    <div class="brand"><img src="{{ asset($config['branding']['logoPath'] ?? 'images/logo-bp.svg') }}" alt="Logo perusahaan" class="brand-logo"><div class="brand-info"><h1>{{ $config['system']['companyName'] ?? 'PT COAL MINING & RESOURCES' }}</h1><p>Accounting & Mining Cost Management System • Local Hosting (Intranet)</p></div></div>
    <div class="header-status"><form method="get" action="{{ route('dashboard') }}" class="period-form"><input type="hidden" name="tab" value="{{ $activeTab }}"><select name="period_year" onchange="this.form.submit()">@foreach (range(2024, (int) date('Y')) as $year)<option value="{{ $year }}" @selected(($period['year'] ?? null) === $year)>{{ $year }}</option>@endforeach</select><select name="period_month" onchange="this.form.submit()">@foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $mi => $mname)<option value="{{ $mi + 1 }}" @selected(($period['month'] ?? null) === ($mi + 1))>{{ $mname }}</option>@endforeach</select><select name="period_view" onchange="this.form.submit()"><option value="mtd" @selected(($period['view'] ?? 'mtd') === 'mtd')>MTD (Bulan Berjalan)</option><option value="ytd" @selected(($period['view'] ?? '') === 'ytd')>YTD (Tahun Berjalan)</option><option value="all" @selected(($period['view'] ?? '') === 'all')>Semua Periode</option></select><noscript><button class="btn btn-outline">Terapkan</button></noscript></form><span class="sync-badge">📅 {{ $period['label'] ?? 'Semua Semester' }}</span>@if ($canImportGl)<a class="btn btn-primary" href="{{ route('dashboard', ['tab' => 'integration']) }}#import-gl">📤 Impor Data GL</a>@endif @if (auth()->user()->isAdministrator())<a class="btn btn-outline" href="{{ route('admin.settings') }}">⚙️ Setup Admin</a>@endif<span class="sync-badge">D365 GL: Konfigurasi</span><span class="sync-badge">Timbangan: Data Lokal</span><strong>{{ auth()->user()->name }} ({{ ucfirst(auth()->user()->role) }})</strong><form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline">Keluar</button></form></div>
</header>
<nav class="nav-tabs-bar" aria-label="Menu dashboard">
    @foreach ($tabs as $key => $label)
        <a class="nav-tab-btn {{ $activeTab === $key ? 'active' : '' }}" href="{{ route('dashboard', ['tab' => $key, 'period_year' => $period['year'], 'period_month' => $period['month'], 'period_view' => $period['view']]) }}">{{ $label }}</a>
    @endforeach
</nav>
<main class="main-content">
    @if (session('success')) <div class="notice">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="notice notice-error">{{ $errors->first() }}</div> @endif

    <section class="tab-pane {{ $activeTab === 'overview' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Ringkasan Operasional & Keuangan</h2><p>Kombinasi data General Ledger dan penimbangan produksi</p></div></div>
        <div class="kpi-grid">
            @foreach ([
                ['TOTAL ALL COST (GL)', 'Rp ' . $num($gt['allCost']), 'Valuation to + Costing to (Ribuan IDR)', ''],
                ['TOTAL PRODUKSI NETTO', $mt($productionSummary['totalNettoMT']), 'NETTO / 1000', 'val'],
                ['RATA-RATA COST / MT', 'Rp ' . $num($costPerMT['overallCostPerMT']) . ' / MT', 'Total Biaya Operasional / Netto MT', 'cost'],
                ['TOTAL NILAI PERSEDIAAN', 'Rp ' . $num($inventoryValuation['totalEndingValueIDR'] / 1000), '4 Stockpile (Ribuan IDR)', 'success'],
                ['LABA KOTOR (GROSS PROFIT)', 'Rp ' . $num($inc['grossProfit']), 'Ribuan IDR', ''],
                ['EBITDA OPERASIONAL', 'Rp ' . $num($inc['operatingProfitEBITDA']), 'Ribuan IDR', ''],
            ] as [$title, $value, $subtitle, $class])
                <div class="kpi-card {{ $class }}"><div class="kpi-title">{{ $title }}</div><div class="kpi-value">{{ $value }}</div><div class="kpi-sub">{{ $subtitle }}</div></div>
            @endforeach
        </div>
        <div class="grid-2">
            <div class="card"><div class="card-title">Alokasi Biaya GL (Valuation vs Costing)</div>
                <div class="allocation-grid"><div class="allocation-card allocation-val"><strong>VALUATION TO (KAPITALISASI STOK)</strong><h2>Rp {{ $num($gt['totalValuation']) }}</h2><small>Mining, Hauling, Wash Plant, BP Port</small></div><div class="allocation-card allocation-cost"><strong>COSTING TO (BEBAN PERIODE)</strong><h2>Rp {{ $num($gt['totalCosting']) }}</h2><small>Mining, Hauling, Wash Plant, Port, GA, Selling</small></div></div>
                <strong>Top Cost Drivers (Komponen Biaya Terbesar)</strong>
                @foreach ($costPerMT['costByAccountGroup'] as $driver)
                    <div class="driver-row"><span>{{ $driver['group'] }}</span><strong>Rp {{ $num($driver['amount']) }}</strong></div>
                    <div class="bar-track"><div class="bar-fill" style="width: {{ max(2, min(100, $gt['allCost'] ? $driver['amount'] / $gt['allCost'] * 100 : 2)) }}%"></div></div>
                @endforeach
            </div>
            <div class="card"><div class="card-title">Tren Penimbangan Harian Netto MT</div><p>Volume batubara keluar timbangan per hari (MT):</p>
                @php $maxTrend = max(1, ...array_column($productionSummary['dailyTrends'], 'nettoMT')); @endphp
                <div class="chart-bars">@foreach ($productionSummary['dailyTrends'] as $day)
                    <div class="chart-column" title="{{ $day['date'] }}: {{ $mt($day['nettoMT']) }}"><div style="height: {{ max(2, $day['nettoMT'] / $maxTrend * 100) }}%"></div><small>{{ substr($day['date'], -2) }}</small></div>
                @endforeach</div><small>Sumber: data penimbangan lokal (NETTO / 1000)</small>
            </div>
        </div>
    </section>
    <section class="tab-pane {{ $activeTab === 'financial' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Laporan Keuangan (Financial Statements)</h2><p>Laba Rugi dan Neraca terintegrasi otomatis dari General Ledger</p></div></div>
        <div class="grid-2"><div class="card"><div class="card-title">Laporan Laba Rugi (Income Statement)<small>{{ $period['label'] ?? 'MTD' }}</small></div><table class="table-accounting"><tbody>
            @foreach ([['Volume Penjualan Batubara', $mt($inc['salesVolumeMT'])], ['Pendapatan Penjualan Batubara', 'Rp ' . $num($inc['revenue']['totalRevenue'])], ['Beban Pokok Penjualan (COGS)', 'Rp ' . $num($inc['cogs']['totalCOGS'])], ['Laba Kotor (Gross Profit)', 'Rp ' . $num($inc['grossProfit'])], ['Margin Laba Kotor (%)', number_format($inc['grossProfitMarginPct'], 1) . '%'], ['Beban Umum & Administrasi', 'Rp ' . $num($inc['operatingExpenses']['generalAdministrative'])], ['Beban Penjualan & Pemasaran', 'Rp ' . $num($inc['operatingExpenses']['sellingExpense'])], ['EBITDA / Laba Operasional', 'Rp ' . $num($inc['operatingProfitEBITDA'])], ['Margin EBITDA (%)', number_format($inc['ebitdaMarginPct'], 1) . '%'], ['Beban Keuangan', 'Rp ' . $num($inc['otherItems']['financeCost'])], ['Selisih Kurs', 'Rp ' . $num($inc['otherItems']['forexGainLoss'])], ['LABA BERSIH SEBELUM PAJAK', 'Rp ' . $num($inc['netProfitBeforeTax'])], ['Net Profit Margin (%)', number_format($inc['netProfitMarginPct'], 1) . '%']] as [$label, $value])<tr><td>{{ $label }}</td><td class="num"><strong>{{ $value }}</strong></td></tr>@endforeach
        </tbody></table></div><div class="card"><div class="card-title">Neraca Keuangan (Balance Sheet)<small>Per {{ $bs['asOfDate'] }}</small></div><table class="table-accounting"><tbody>
            @foreach ([['ASET LANCAR (CURRENT ASSETS)', null], ['Kas dan Setara Kas', 'Rp ' . $num($bs['assets']['currentAssets']['cashAndBank'])], ['Piutang Usaha', 'Rp ' . $num($bs['assets']['currentAssets']['tradeReceivables'])], ['Persediaan Batubara (4 Stockpile)', 'Rp ' . $num($bs['assets']['currentAssets']['coalInventories']['totalInventory'])], ['Total Aset Lancar', 'Rp ' . $num($bs['assets']['currentAssets']['totalCurrentAssets'])], ['ASET TIDAK LANCAR (NON-CURRENT ASSETS)', null], ['Alat Berat & Infrastruktur (Netto)', 'Rp ' . $num($bs['assets']['nonCurrentAssets']['totalNonCurrentAssets'])], ['TOTAL ASET', 'Rp ' . $num($bs['assets']['totalAssets'])], ['LIABILITAS & KEWAJIBAN', null], ['Utang Usaha & Biaya Akrual', 'Rp ' . $num($bs['liabilities']['currentLiabilities']['totalCurrentLiabilities'])], ['Liabilitas Jangka Panjang', 'Rp ' . $num($bs['liabilities']['nonCurrentLiabilities']['totalNonCurrentLiabilities'])], ['Total Liabilitas', 'Rp ' . $num($bs['liabilities']['totalLiabilities'])], ['EKUITAS (EQUITY)', null], ['Modal Saham & Saldo Laba', 'Rp ' . $num($bs['equity']['shareCapital'] + $bs['equity']['retainedEarnings'])], ['Laba Periode Berjalan', 'Rp ' . $num($bs['equity']['currentYearEarnings'])], ['Total Ekuitas', 'Rp ' . $num($bs['equity']['totalEquity'])], ['TOTAL LIABILITAS & EKUITAS', 'Rp ' . $num($bs['liabilities']['totalLiabilities'] + $bs['equity']['totalEquity'])]] as [$label, $value])<tr><td><strong>{{ $label }}</strong></td><td class="num">{{ $value ?? '' }}</td></tr>@endforeach
        </tbody></table></div></div>
    </section>

    <section class="tab-pane {{ $activeTab === 'integration' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Pusat Integrasi & Sumber Data</h2><p>Konfigurasi konektor D365 dan Google Sheets</p></div></div>
        <div class="table-card" id="d365-live-progress" hidden><div class="table-heading">Progress Sinkronisasi Live</div><div class="table-responsive"><table class="table-accounting"><thead><tr class="th-general"><th>Entity</th><th>Status</th><th>Progress</th><th>Data</th></tr></thead><tbody id="d365-progress-body"></tbody></table></div><small id="d365-progress-note">Menunggu worker queue memproses sync.</small></div>
        <div class="grid-2"><div class="card"><div class="card-title">Microsoft Dynamics 365 OData Connector <span class="sync-badge">{{ ($config['d365Config']['environmentUrl'] ?? '') !== '' ? 'Terhubung' : 'Konfigurasi' }}</span></div><p>Menarik data dari data entity OData D365 ke tabel staging MySQL, incremental berdasarkan kursor SourceKey.</p><div class="integration-details"><div><strong>Environment URL:</strong> <code>{{ $config['d365Config']['environmentUrl'] ?? '-' }}</code></div><div><strong>Interval Sync:</strong> {{ $config['d365Config']['syncIntervalMinutes'] ?? 15 }} menit {{ ($config['d365Config']['autoSync'] ?? false) ? '(otomatis aktif)' : '(manual)' }}</div><div><strong>Sinkronisasi Terakhir:</strong> {{ $config['system']['lastSyncD365'] ?? '-' }}</div></div><a class="btn btn-outline" href="{{ route('d365.data.index') }}">📋 Lihat Tabel Staging</a></div><div class="card"><div class="card-title">Google Sheets Weighbridge Connector <span class="sync-badge">Data Lokal</span></div><p>Membaca data penimbangan dan mengonversi NETTO menjadi Metric Ton.</p><div class="integration-details"><div><strong>Spreadsheet ID:</strong> <code>{{ $config['sheetsConfig']['spreadsheetId'] ?? '-' }}</code></div><div><strong>Sheet Tab:</strong> <code>{{ $config['sheetsConfig']['sheetName'] ?? '-' }}</code></div><div><strong>Formula MT:</strong> <code>NETTO / 1000</code></div><div><strong>Sinkronisasi Terakhir:</strong> {{ $config['system']['lastSyncSheets'] ?? '-' }}</div></div></div></div>
        @if ($canImportGl)<div class="table-card" id="d365-sync"><div class="table-heading">Sinkronisasi Data Entity D365</div><form id="d365-sync-form" action="{{ route('d365.sync') }}" method="post" class="upload-form">@csrf<input type="hidden" name="entity" value="all"><button class="btn btn-primary">🔄 Tarik Semua Entity</button></form><div class="table-responsive"><table class="table-accounting"><thead><tr class="th-general"><th>Data Entity (Server)</th><th>Mode</th><th>Baris Staging</th><th>Status Sinkron Terakhir</th><th>Kursor</th><th></th></tr></thead><tbody>@foreach ($d365Status as $entity)<tr><td><strong>{{ $entity['label'] }}</strong><br><code>{{ $entity['entity'] }}</code></td><td>{{ $entity['mode'] === 'incremental' ? 'Incremental' : 'Snapshot' }}</td><td class="num">{{ number_format($entity['staging_count'] ?? 0, 0, ',', '.') }}</td><td>@if ($entity['last']){{ $entity['last']->status === 'success' ? '✅' : ($entity['last']->status === 'failed' ? '❌' : '⏳') }} {{ $entity['last']->status }}<br><small>{{ $entity['last']->updated_at }} — {{ $entity['last']->fetched }} baris</small>@if ($entity['last']->message)<br><small style="color:#b3261e">{{ $entity['last']->message }}</small>@endif@else<span>Belum pernah sync</span>@endif</td><td><code>{{ $entity['last']->max_source_key ?? '-' }}</code></td><td><form action="{{ route('d365.sync') }}" method="post">@csrf<input type="hidden" name="entity" value="{{ $entity['key'] }}"><button class="btn btn-outline">Tarik</button></form></td></tr>@endforeach</tbody></table></div><small>Master data (vendor, customer, PO, invoice, inventory) ditarik mode snapshot karena environment sandbox belum menyimpan datanya; tabel tetap terisi otomatis begitu data tersedia.</small></div>@endif
        @if ($canImportGl)<div class="table-card" id="import-gl"><div class="table-heading">Impor Data General Ledger ke MySQL</div><form action="{{ route('gl.import') }}" method="post" enctype="multipart/form-data" class="upload-form">@csrf<input type="file" name="gl_file" accept=".xlsx,.xls,.csv,.tsv,.txt" required><button class="btn btn-primary">📤 Impor & Hitung Ulang</button></form><small>Format yang didukung: Excel, CSV, TSV, TXT. Data lama akan digantikan oleh file impor.</small></div>@endif
        <div class="table-card"><div class="table-heading">15 Tiket Timbangan Terbaru</div><div class="table-responsive"><table class="table-accounting"><thead><tr class="th-general"><th>Tgl & Jam</th><th>Produk</th><th>No Polisi</th><th>Sopir</th><th>Jembatan</th><th>BRUTO (Kg)</th><th>TARA (Kg)</th><th>NETTO (Kg)</th><th>NETTO (MT)</th><th>Operator</th></tr></thead><tbody>@foreach ($productionSummary['recentTickets'] as $ticket)<tr><td>{{ $ticket['Tgl Keluar'] }} {{ $ticket['Jam Keluar'] }}</td><td><strong>{{ $ticket['Product'] }}</strong></td><td>{{ $ticket['No Pol'] }}</td><td>{{ $ticket['Sopir'] }}</td><td>{{ $ticket['WEIGHBRIDGE'] }}</td><td class="num">{{ number_format($ticket['BRUTO'], 0, ',', '.') }}</td><td class="num">{{ number_format($ticket['TARA'], 0, ',', '.') }}</td><td class="num"><strong>{{ number_format($ticket['NETTO'], 0, ',', '.') }}</strong></td><td class="num"><strong>{{ $mt($ticket['NETTO'] / 1000) }}</strong></td><td>{{ $ticket['Operator'] }}</td></tr>@endforeach</tbody></table></div></div>
    </section>
    
    <section class="tab-pane {{ $activeTab === 'cost' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Analisis Biaya per Metric Ton (Cost / MT)</h2><p>Kombinasi biaya akuntansi dengan volume timbangan</p></div></div>
        <div class="cost-stage-grid">@foreach ($costPerMT['stages'] as $stage)<div class="cost-stage-card"><h4>{{ $stage['stage'] }}</h4><div class="cost-stage-rate">Rp {{ $num($stage['costPerMT']) }}</div><div class="cost-stage-unit">per MT Tahapan ({{ $mt($stage['metricTons']) }})</div><small>Total Biaya: Rp {{ $num($stage['totalCost']) }} (Ribuan IDR)</small></div>@endforeach</div>
        <div class="table-card"><div class="table-heading">Rincian Biaya per Tahapan Pertambangan Batubara</div><div class="table-responsive"><table class="table-accounting"><thead><tr class="th-general"><th>Aktivitas Pertambangan</th><th>Biaya Valuation to ('000 Rp)</th><th>Biaya Costing to ('000 Rp)</th><th>Total Biaya ('000 Rp)</th><th>Volume Operasi (MT)</th><th>Cost / MT Tahapan</th><th>Cost / MT Total Produksi</th></tr></thead><tbody>@foreach ($costPerMT['stages'] as $stage)<tr><td><strong>{{ $stage['stage'] }}</strong></td><td class="num">{{ $num($stage['valuationCost']) }}</td><td class="num">{{ $num($stage['operatingCost']) }}</td><td class="num"><strong>{{ $num($stage['totalCost']) }}</strong></td><td class="num">{{ $mt($stage['metricTons']) }}</td><td class="num"><strong>Rp {{ $num($stage['costPerMT']) }}</strong></td><td class="num">Rp {{ $num($stage['costPerTotalMT']) }}</td></tr>@endforeach</tbody></table></div></div>
    </section>

    <section class="tab-pane {{ $activeTab === 'inventory' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Valuasi Persediaan Batubara (Moving Average)</h2><p>Perhitungan persediaan bergerak multi-stockpile dari timbangan dan biaya kapitalisasi GL</p></div>@if ($canEditInventory)<a class="btn btn-outline" href="#inventory-setup">⚙️ Setup Saldo Awal</a>@endif</div>
        <div class="stockpile-flow">@foreach ($inventoryValuation['stages'] as $stage)<div class="stage-card"><span class="stage-badge">{{ $stage['location'] }}</span><h3>{{ $stage['name'] }}</h3><div class="stage-metrics">
            <div class="stage-metric-row"><span>Saldo Awal:</span><span>{{ $mt($stage['beginningQtyMT']) }} (Rp {{ $num($stage['beginningValueIDR'] / 1000) }})</span></div><div class="stage-metric-row"><span>Produksi / Masuk:</span><strong>+{{ $mt($stage['inflowQtyMT']) }}</strong></div><div class="stage-metric-row"><span>Biaya Valuation to:</span><span>+Rp {{ $num($stage['inflowCostValuationIDR'] / 1000) }}</span></div><div class="stage-metric-row"><span>Moving Avg Rate:</span><strong>Rp {{ $num($stage['movingAverageRateIDR']) }} / MT</strong></div><div class="stage-metric-row"><span>Keluar / Transfer:</span><span>-{{ $mt($stage['outflowQtyMT']) }}</span></div><div class="stage-metric-row"><span>Saldo Akhir MT:</span><strong>{{ $mt($stage['endingQtyMT']) }}</strong></div><div class="stage-metric-row"><span>Total Nilai Persediaan:</span><strong>Rp {{ $num($stage['endingValueIDR'] / 1000) }}</strong></div>
        </div></div>@endforeach</div>
        <div class="table-card"><div class="table-heading">Buku Pembantu Persediaan Batubara (Stock Ledger Moving Average)</div><div class="table-responsive"><table class="table-accounting"><thead><tr class="th-general"><th>Tahapan Persediaan</th><th>Saldo Awal Qty (MT)</th><th>Saldo Awal Rate (Rp/MT)</th><th>Saldo Awal Nilai ('000 Rp)</th><th>Penerimaan Qty (MT)</th><th>Penerimaan Nilai ('000 Rp)</th><th>Moving Avg Rate (Rp/MT)</th><th>Pengeluaran Qty (MT)</th><th>Pengeluaran Nilai ('000 Rp)</th><th>Saldo Akhir Qty (MT)</th><th>Saldo Akhir Nilai ('000 Rp)</th></tr></thead><tbody>@foreach ($inventoryValuation['stages'] as $stage)<tr><td><strong>{{ $stage['name'] }}</strong><br><small>{{ $stage['location'] }}</small></td><td class="num">{{ $mt($stage['beginningQtyMT']) }}</td><td class="num">Rp {{ $num($stage['beginningRateIDR']) }}</td><td class="num">{{ $num($stage['beginningValueIDR'] / 1000) }}</td><td class="num">{{ $mt($stage['inflowQtyMT']) }}</td><td class="num">{{ $num($stage['inflowTotalValueIDR'] / 1000) }}</td><td class="num">Rp {{ $num($stage['movingAverageRateIDR']) }}</td><td class="num">{{ $mt($stage['outflowQtyMT']) }}</td><td class="num">{{ $num($stage['outflowValueIDR'] / 1000) }}</td><td class="num">{{ $mt($stage['endingQtyMT']) }}</td><td class="num">{{ $num($stage['endingValueIDR'] / 1000) }}</td></tr>@endforeach</tbody></table></div></div>
         @if ($canEditInventory)<div class="card" id="inventory-setup"><div class="card-title">⚙️ Setup Saldo Awal Persediaan Batubara</div><form action="{{ route('inventory.save') }}" method="post">@csrf<div class="inventory-form-grid">@foreach ($inventoryValuation['stages'] as $stage)<div class="form-group"><label for="{{ $stage['id'] }}-qty">{{ $stage['name'] }} (MT)</label><input id="{{ $stage['id'] }}-qty" name="{{ $stage['id'] }}_qty" type="number" step="any" min="0" required value="{{ old($stage['id'] . '_qty', $stage['beginningQtyMT']) }}"></div><div class="form-group"><label for="{{ $stage['id'] }}-value">Nilai {{ $stage['name'] }} (IDR)</label><input id="{{ $stage['id'] }}-value" name="{{ $stage['id'] }}_value" type="number" step="any" min="0" required value="{{ old($stage['id'] . '_value', $stage['beginningValueIDR']) }}"></div>@endforeach</div><button class="btn btn-primary">Simpan Setup</button></form></div>@endif
     </section>

    <section class="tab-pane {{ $activeTab === 'matrix' ? 'active' : '' }}">
        <div class="section-header"><div class="section-title"><h2>Report Cost: Matriks Valuation to & Costing to</h2><p>Nilai dalam Ribuan IDR</p></div><div class="section-actions"><a class="btn btn-outline" href="{{ route('d365.data.main_account_summary', ['date_from' => $period['start'], 'date_to' => $period['end']]) }}">∑ Cek Sum Main Account</a><a class="btn btn-primary" href="{{ route('matrix.export', ['period_year' => $period['year'], 'period_month' => $period['month'], 'period_view' => $period['view']]) }}">📥 Export Excel/CSV</a></div></div>
        <form class="section-actions matrix-filters" method="get" action="{{ route('dashboard') }}"><input type="hidden" name="tab" value="matrix"><input class="input-search" name="search" value="{{ $search }}" placeholder="🔍 Cari Kode / Nama Akun..."><label><input type="checkbox" name="parents_only" value="1" {{ $parentsOnly ? 'checked' : '' }}> Hanya Akun Induk</label><label><input type="checkbox" name="hide_zero" value="1" {{ $hideZero ? 'checked' : '' }}> Sembunyikan Saldo 0</label><button class="btn btn-outline">Terapkan Filter</button></form>
        <div class="table-card"><div class="table-responsive"><table class="table-accounting">
            <thead><tr><th rowspan="2" class="th-general">AccountCode</th><th rowspan="2" class="th-general">AccountName</th><th rowspan="2" class="th-general">Cek</th><th rowspan="2" class="th-general">ALL Cost</th><th colspan="4" class="th-val-group col-header-group">Valuation to (Stok Persediaan)</th><th colspan="6" class="th-cost-group col-header-group">Costing to (Beban Periode)</th></tr><tr>@foreach (['Mining','Coal Hauling','Washing Plant','BP PORT'] as $label)<th class="th-val-sub">{{ $label }}</th>@endforeach @foreach (['Mining','Coal Hauling','Washing Plant','BP PORT','GA','Selling Expense'] as $label)<th class="th-cost-sub">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@foreach ($costMatrix['rows'] as $row)
                @continue($parentsOnly && !$row['isParent'])
                @continue($hideZero && !$row['allCost'])
                @continue($search && !str_contains(strtolower($row['code'] . ' ' . $row['name']), strtolower($search)))
                <tr class="{{ $row['isParent'] ? 'parent-row' : 'child-row' }}"><td><strong>{{ $row['code'] }}</strong></td><td class="td-text">{{ $row['isParent'] ? '▾' : '　' }} {{ $row['name'] }}</td><td class="num">-</td><td class="num"><strong>{{ $num($row['allCost']) }}</strong></td>@foreach (['valMining','valHauling','valWP','valPort','costMining','costHauling','costWP','costPort','costGA','costSelling'] as $field)<td class="num {{ $row[$field] < 0 ? 'num-neg' : '' }}">{{ $num($row[$field]) }}</td>@endforeach</tr>
            @endforeach</tbody><tfoot><tr class="grand-total-row"><td>TOTAL</td><td class="td-text">Grand Total Biaya</td><td class="num">-</td><td class="num">{{ $num($gt['allCost']) }}</td>@foreach (['valMining','valHauling','valWP','valPort','costMining','costHauling','costWP','costPort','costGA','costSelling'] as $field)<td class="num">{{ $num($gt[$field]) }}</td>@endforeach</tr></tfoot>
        </table></div></div>
    </section>
</main>
<script>
(() => {
    const form = document.getElementById('d365-sync-form');
    const panel = document.getElementById('d365-live-progress');
    const body = document.getElementById('d365-progress-body');
    const note = document.getElementById('d365-progress-note');
    if (!form || !panel || !body) return;
    const progressUrl = @json(route('d365.progress'));
    const syncUrl = @json(route('d365.sync'));
    const stagingMarchUrl = @json(route('d365.data.staging', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']));
    const csrf = form.querySelector('input[name="_token"]').value;
    let timer = null;
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const refresh = async () => {
        const response = await fetch(progressUrl, {headers: {'Accept': 'application/json'}});
        if (!response.ok) return;
        const payload = await response.json();
        const rows = Array.isArray(payload) ? payload : (payload.rows || []);
        const queued = Number(Array.isArray(payload) ? 0 : (payload.queued || 0));
        body.innerHTML = rows.map((row) => {
            const percent = Math.max(0, Math.min(100, Number(row.progress || 0)));
            const status = row.status === 'success' ? '✅ Selesai' : (row.status === 'failed' ? '❌ Gagal' : (row.status === 'running' ? '⏳ Berjalan' : 'Menunggu'));
            const total = row.total === null ? '' : ` / ${Number(row.total).toLocaleString('id-ID')}`;
            return `<tr><td><code>${esc(row.key)}</code></td><td>${status}</td><td><div class="bar-track"><div class="bar-fill" style="width:${percent}%"></div></div><small>${percent}%</small></td><td>${Number(row.fetched).toLocaleString('id-ID')}${total}</td></tr>`;
        }).join('');
        const running = rows.some((row) => row.status === 'running');
        const active = queued > 0 || running;
        if (active) panel.hidden = false;
        note.innerHTML = queued > 0 && !running
            ? 'Sync sudah masuk antrean. Menunggu queue worker…'
            : (running ? 'Sinkronisasi sedang berjalan…' : `Progress terakhir diperbarui. <a href="${stagingMarchUrl}">Buka staging periode Maret 2026</a>`);
        if (!active && timer) { clearInterval(timer); timer = null; }
        return active;
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        formData.set('date_from', '2026-03-01');
        formData.set('date_to', '2026-04-01');
        panel.hidden = false;
        note.textContent = 'Menempatkan sync ke antrean…';
        const response = await fetch(syncUrl, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: formData});
        if (!response.ok) { note.textContent = 'Gagal memasukkan sync ke antrean.'; return; }
        await refresh();
        if (!timer) timer = setInterval(refresh, 2000);
    });

    // Pulihkan polling jika halaman dibuka kembali saat queue masih bekerja.
    refresh().then((active) => {
        if (active && !timer) timer = setInterval(refresh, 2000);
    });
})();
</script>
<footer class="app-footer"><strong>PT Coal Mining & Resources</strong> • Sistem Dashboard Akuntansi & Biaya Produksi Batubara <span>Hosting Lokal • PHP Laravel & MySQL</span></footer>
</body>
</html>
