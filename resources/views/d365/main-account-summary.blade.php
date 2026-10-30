<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sum Main Account D365</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        .toolbar{display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}.toolbar .form-group{display:flex;flex-direction:column;gap:.25rem}.toolbar label{font-size:.72rem;color:var(--text-muted);font-weight:600}.toolbar input{padding:.4rem .65rem;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-card);color:var(--text-main)}
        .table-wrap{overflow:auto}.summary-table{width:100%;border-collapse:collapse;font-size:.84rem}.summary-table th{position:sticky;top:0;background:#1f2b3d;color:#fff;border:1px solid #d5deea;padding:.7rem .75rem;text-align:left;white-space:nowrap}.summary-table td{border:1px solid #d5deea;padding:.48rem .75rem;white-space:nowrap}.summary-table tbody tr:nth-child(even){background:#f5f7fa}.summary-table tbody tr:hover{background:#eaf3fb}.summary-table tfoot td{font-weight:700;background:#e9eef5}.num{text-align:right;font-family:var(--font-mono)}.meta{display:flex;gap:1rem;flex-wrap:wrap;margin:.8rem 0;font-size:.8rem;color:var(--text-muted)}
    </style>
</head>
<body>
<header class="app-header"><div class="brand"><img src="{{ asset($config['branding']['logoPath'] ?? 'images/logo-bp.svg') }}" alt="Logo" class="brand-logo"><div class="brand-info"><h1>{{ $config['system']['companyName'] ?? 'PT COAL MINING & RESOURCES' }}</h1><p>Sum Main Account D365</p></div></div><div class="header-status"><a class="btn btn-outline" href="{{ route('d365.data.index') }}">← Semua Entity</a><a class="btn btn-outline" href="{{ route('d365.data.staging') }}">Staging CSV</a></div></header>
<main class="main-content">
    <div class="section-header"><div class="section-title"><h2>Cek Total Sum Main Account</h2><p>Ringkasan nilai debit/kredit dari <code>d365s_gl_entries</code>, dikelompokkan berdasarkan Main Account. Kolom Cek menunjukkan jumlah baris GL.</p></div></div>
    <div class="card"><form method="get" action="{{ route('d365.data.main_account_summary') }}" class="toolbar"><div class="form-group"><label>Cari kode atau nama akun</label><input name="search" value="{{ $search }}" placeholder="contoh: 5000000 atau salary"></div><div class="form-group"><label>Dari tanggal</label><input type="date" name="date_from" value="{{ $dateFrom }}"></div><div class="form-group"><label>Sampai tanggal</label><input type="date" name="date_to" value="{{ $dateTo }}"></div><button class="btn btn-primary">Terapkan</button><a class="btn btn-outline" href="{{ route('d365.data.main_account_summary') }}">Reset</a></form></div>
    <div class="meta"><span>Akun: <strong>{{ number_format($rows->count(), 0, ',', '.') }}</strong></span><span>Total baris GL: <strong>{{ number_format($totalRows, 0, ',', '.') }}</strong></span><span>Total ALL Cost: <strong>{{ number_format($grandTotal, 2, ',', '.') }}</strong></span></div>
    <div class="table-card"><div class="table-wrap"><table class="summary-table"><thead><tr><th>AccountCode</th><th>AccountName</th><th>Cek</th><th>ALL Cost</th></tr></thead><tbody>@forelse ($rows as $row)<tr><td><strong>{{ $row['account_code'] }}</strong></td><td>{{ $row['account_name'] }}</td><td class="num">{{ number_format($row['entry_count'], 0, ',', '.') }}</td><td class="num"><strong>{{ number_format($row['all_cost'], 2, ',', '.') }}</strong></td></tr>@empty<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">Belum ada data GL staging. Jalankan sync D365 terlebih dahulu.</td></tr>@endforelse</tbody><tfoot><tr><td>TOTAL</td><td>Grand Total</td><td class="num">{{ number_format($totalRows, 0, ',', '.') }}</td><td class="num">{{ number_format($grandTotal, 2, ',', '.') }}</td></tr></tfoot></table></div></div>
</main>
</body>
</html>
