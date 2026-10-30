<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staging Import D365</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        .toolbar{display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}.toolbar .form-group{display:flex;flex-direction:column;gap:.25rem}.toolbar label{font-size:.72rem;color:var(--text-muted);font-weight:600}.toolbar input{padding:.4rem .65rem;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-card);color:var(--text-main)}
        .table-wrap{overflow:auto}.csv-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:.78rem}.csv-table th{position:sticky;top:0;background:var(--bg-surface);border-bottom:2px solid var(--border-color);padding:.55rem .7rem;text-align:left;white-space:nowrap;color:var(--text-muted);font-size:.7rem;text-transform:uppercase}.csv-table td{border-bottom:1px solid var(--border-color);padding:.45rem .7rem;white-space:nowrap;max-width:280px;overflow:hidden;text-overflow:ellipsis}.csv-table tr:hover td{background:var(--primary-light)}.num{text-align:right;font-family:var(--font-mono)}.muted{color:var(--text-muted)}.meta{display:flex;gap:1rem;flex-wrap:wrap;margin:.8rem 0;font-size:.8rem;color:var(--text-muted)}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:1rem;flex-wrap:wrap}.pager a,.pager span{padding:.3rem .6rem;border:1px solid var(--border-color);border-radius:5px;text-decoration:none;color:var(--text-main);margin-left:.25rem}.pager span{background:var(--primary);color:#fff}
    </style>
</head>
<body>
<header class="app-header"><div class="brand"><img src="{{ asset($config['branding']['logoPath'] ?? 'images/logo-bp.svg') }}" alt="Logo" class="brand-logo"><div class="brand-info"><h1>{{ $config['system']['companyName'] ?? 'PT COAL MINING & RESOURCES' }}</h1><p>Staging Import D365</p></div></div><div class="header-status"><a class="btn btn-outline" href="{{ route('d365.data.index') }}">← Semua Entity</a><a class="btn btn-outline" href="{{ route('dashboard', ['tab' => 'integration']) }}">Dashboard</a></div></header>
<main class="main-content">
    <div class="section-header"><div class="section-title"><h2>Staging Format Import GL</h2><p>Gabungan GL line, GL header, master akun, dan financial dimensions. PO DT Number, Valuation to, dan Costing to dibiarkan kosong sampai reference mapping tersedia.</p></div></div>
    <div class="card"><form method="get" action="{{ route('d365.data.staging') }}" class="toolbar"><div class="form-group"><label>Cari</label><input name="search" value="{{ $search }}" placeholder="akun atau deskripsi"></div><div class="form-group"><label>Dari tanggal</label><input type="date" name="date_from" value="{{ $dateFrom }}"></div><div class="form-group"><label>Sampai tanggal</label><input type="date" name="date_to" value="{{ $dateTo }}"></div><button class="btn btn-primary">Terapkan</button><a class="btn btn-outline" href="{{ route('d365.data.staging', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31']) }}">Maret 2026</a><a class="btn btn-outline" href="{{ route('d365.data.staging') }}">Reset</a><a class="btn btn-primary" href="{{ route('d365.data.staging.export_xls', array_filter(['search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo])) }}">📥 Export XLS</a></form></div>
    <div class="meta"><span>Total: <strong>{{ number_format($rows->total(), 0, ',', '.') }}</strong> baris</span><span>Halaman {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span></div>
    <div class="table-card"><div class="table-wrap"><table class="csv-table"><thead><tr>@foreach ($columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
    @forelse ($rows as $row)<tr>@foreach ($columns as $key => $label) @php($value = $row[$key] ?? null)<td class="{{ in_array($key, ['fuel_quantity','transaction_amount','amount_idr','amount','reporting_amount'], true) ? 'num' : '' }}">{{ ($value === null || $value === '') ? '—' : (is_numeric($value) && in_array($key, ['fuel_quantity','transaction_amount','amount_idr','amount','reporting_amount'], true) ? number_format((float) $value, 2, ',', '.') : $value) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}" class="muted" style="text-align:center;padding:2rem">Belum ada data staging. Jalankan sync D365 terlebih dahulu.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="pager"><span>Menampilkan {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} dari {{ number_format($rows->total(), 0, ',', '.') }}</span><div>{!! $rows->links() !!}</div></div>
</main>
</body>
</html>
