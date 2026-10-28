<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $entityDef['label'] }} — Data MSD D365</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        .data-toolbar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:flex-end; margin-bottom:1.1rem; }
        .data-toolbar .form-group { display:flex; flex-direction:column; gap:.25rem; }
        .data-toolbar label { font-size:.72rem; color:var(--text-muted); font-weight:600; }
        .data-toolbar input { padding:.38rem .7rem; border:1px solid var(--border-color); border-radius:6px; font-size:.82rem; background:var(--bg-card); color:var(--text-main); }
        .data-toolbar input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-light); }
        .data-toolbar .btn { align-self:flex-end; }
        .meta-bar { display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:.9rem; }
        .meta-badge { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:6px; padding:.35rem .85rem; font-size:.78rem; }
        .meta-badge strong { color:var(--text-main); }
        .tbl-wrap { overflow-x:auto; }
        table.tbl-data { width:100%; border-collapse:collapse; font-size:.80rem; }
        table.tbl-data th { background:var(--bg-surface); border-bottom:2px solid var(--border-color); padding:.55rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); white-space:nowrap; position:sticky; top:0; z-index:2; }
        table.tbl-data td { padding:.45rem .75rem; border-bottom:1px solid var(--border-color); color:var(--text-main); vertical-align:top; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        table.tbl-data tr:hover td { background:var(--primary-light); }
        table.tbl-data tfoot td { background:var(--bg-surface); font-weight:700; border-top:2px solid var(--border-color); }
        td.num, tfoot td.num { text-align:right; font-family:var(--font-mono); }
        .paginator { display:flex; gap:.4rem; align-items:center; justify-content:space-between; margin-top:1rem; flex-wrap:wrap; }
        .paginator-info { font-size:.78rem; color:var(--text-muted); }
        .paginator-links { display:flex; gap:.3rem; }
        .paginator-links a, .paginator-links span { padding:.3rem .65rem; border-radius:6px; font-size:.78rem; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); text-decoration:none; }
        .paginator-links span.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .paginator-links a:hover { background:var(--primary-light); }
        .back-link { display:inline-flex; align-items:center; gap:.35rem; font-size:.82rem; color:var(--primary); text-decoration:none; margin-bottom:1rem; }
        .back-link:hover { text-decoration:underline; }
        .entity-pill { display:inline-flex; align-items:center; gap:.4rem; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:100px; padding:.25rem .8rem; font-size:.75rem; }
        .entity-pill code { font-size:.72rem; }
        .empty-state { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
        .empty-state .empty-icon { font-size:3rem; margin-bottom:.75rem; }
    </style>
</head>
<body>
<header class="app-header">
    <div class="brand">
        <img src="{{ asset($config['branding']['logoPath'] ?? 'images/logo-bp.svg') }}" alt="Logo" class="brand-logo">
        <div class="brand-info">
            <h1>{{ $config['system']['companyName'] ?? 'PT COAL MINING & RESOURCES' }}</h1>
            <p>Data Tampungan — {{ $entityDef['label'] }}</p>
        </div>
    </div>
    <div class="header-status">
        <a class="btn btn-outline" href="{{ route('d365.data.index') }}">← Semua Entity</a>
        <a class="btn btn-outline" href="{{ route('dashboard', ['tab' => 'integration']) }}">Dashboard</a>
        @if (auth()->user()->isAdministrator())
            <a class="btn btn-outline" href="{{ route('admin.settings') }}">⚙️ Admin</a>
        @endif
        <strong>{{ auth()->user()->name }}</strong>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline">Keluar</button></form>
    </div>
</header>

<main class="main-content">
    @if (session('success')) <div class="notice">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="notice notice-error">{{ $errors->first() }}</div> @endif

    {{-- Breadcrumb --}}
    <a class="back-link" href="{{ route('d365.data.index') }}">
        ← Kembali ke Daftar Entity
    </a>

    {{-- Title & entity info --}}
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
        <h2 style="margin:0;font-size:1.3rem">📦 {{ $entityDef['label'] }}</h2>
        <span class="entity-pill">
            OData: <code>{{ $entityDef['entity'] }}</code>
        </span>
        <span class="entity-pill">
            Staging: <code>{{ $viewConfig['table'] }}</code>
        </span>
        <span class="entity-pill {{ $entityDef['mode'] === 'incremental' ? '' : 'badge-ok' }}">
            {{ $entityDef['mode'] === 'incremental' ? '↑ Incremental' : '⟳ Snapshot' }}
        </span>
        @if (isset($entityDef['dataAreaField']))
            <span class="entity-pill">
                Company filter: <code>{{ $entityDef['dataAreaField'] }} = {{ $config['d365Config']['company'] ?? 'BP' }}</code>
            </span>
        @endif
    </div>

    {{-- Filter toolbar --}}
    <div class="card" style="padding:1rem;margin-bottom:1.1rem">
        <form method="get" action="{{ route('d365.data.show', $entityKey) }}" class="data-toolbar">
            <div class="form-group">
                <label>🔍 Cari</label>
                <input name="search" value="{{ $search }}" placeholder="Cari {{ implode(', ', array_slice($viewConfig['search'], 0, 2)) }}...">
            </div>
            @if ($viewConfig['dateCol'])
            <div class="form-group">
                <label>📅 Dari Tanggal</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}">
            </div>
            <div class="form-group">
                <label>📅 Sampai Tanggal</label>
                <input type="date" name="date_to" value="{{ $dateTo }}">
            </div>
            @endif
            <button type="submit" class="btn btn-primary">Terapkan</button>
            <a href="{{ route('d365.data.show', $entityKey) }}" class="btn btn-outline">Reset</a>

            @if (in_array(auth()->user()->role, ['administrator', 'accountant'], true))
                <form action="{{ route('d365.sync') }}" method="post" style="align-self:flex-end">
                    @csrf<input type="hidden" name="entity" value="{{ $entityKey }}">
                    <button class="btn btn-outline">↻ Tarik Ulang</button>
                </form>
            @endif
        </form>
    </div>

    {{-- Meta info --}}
    <div class="meta-bar">
        <div class="meta-badge">
            Total data: <strong>{{ number_format($rows->total(), 0, ',', '.') }} baris</strong>
        </div>
        <div class="meta-badge">
            Halaman: <strong>{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</strong>
        </div>
        @foreach ($amtTotals as $col => $total)
            <div class="meta-badge">
                Total {{ $columns[$col] ?? $col }}: <strong>{{ number_format($total, 2, ',', '.') }}</strong>
            </div>
        @endforeach
        @if ($search || $dateFrom || $dateTo)
            <div class="meta-badge" style="background:var(--primary-light);border-color:var(--primary);color:var(--primary)">
                🔎 Filter aktif
                @if ($search) · "{{ $search }}" @endif
                @if ($dateFrom) · dari {{ $dateFrom }} @endif
                @if ($dateTo) · s/d {{ $dateTo }} @endif
            </div>
        @endif
    </div>

    {{-- Data table --}}
    <div class="table-card">
        @if ($rows->isEmpty())
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <p>Tidak ada data ditemukan.
                @if (!$search && !$dateFrom && !$dateTo)
                    Lakukan sinkronisasi terlebih dahulu.
                @else
                    Coba ubah filter pencarian.
                @endif
                </p>
                @if (in_array(auth()->user()->role, ['administrator', 'accountant'], true))
                    <form action="{{ route('d365.sync') }}" method="post">
                        @csrf<input type="hidden" name="entity" value="{{ $entityKey }}">
                        <button class="btn btn-primary">🔄 Tarik Data Sekarang</button>
                    </form>
                @endif
            </div>
        @else
        <div class="tbl-wrap">
            <table class="tbl-data">
                <thead>
                    <tr>
                        <th>#</th>
                        @foreach ($columns as $col => $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $row)
                    <tr>
                        <td style="color:var(--text-muted)">{{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}</td>
                        @foreach (array_keys($columns) as $col)
                        @php
                            $val = $row->$col ?? null;
                            $isAmt = in_array($col, $viewConfig['amtCols'], true);
                        @endphp
                        <td class="{{ $isAmt ? 'num' : '' }}" title="{{ is_string($val) && strlen($val) > 50 ? $val : '' }}">
                            @if ($val === null || $val === '')
                                <span style="color:var(--text-muted)">—</span>
                            @elseif ($isAmt)
                                {{ number_format((float)$val, 2, ',', '.') }}
                            @elseif ($col === 'posted')
                                {{ $val ? '✅ Ya' : '❌ Tidak' }}
                            @else
                                {{ Str::limit((string)$val, 60) }}
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
                @if (count($amtTotals) > 0)
                <tfoot>
                    <tr>
                        <td colspan="{{ count($columns) + 1 - count($amtTotals) }}">
                            <strong>Total {{ $rows->total() > $rows->perPage() ? '(seluruh data terfilter)' : '' }}</strong>
                        </td>
                        @foreach (array_keys($columns) as $col)
                            @if (in_array($col, $viewConfig['amtCols'], true))
                                <td class="num"><strong>{{ number_format($amtTotals[$col] ?? 0, 2, ',', '.') }}</strong></td>
                            @endif
                        @endforeach
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        {{-- Pagination --}}
        <div class="paginator">
            <div class="paginator-info">
                Menampilkan {{ $rows->firstItem() }}–{{ $rows->lastItem() }}
                dari {{ number_format($rows->total(), 0, ',', '.') }} baris
            </div>
            <div class="paginator-links">
                @if ($rows->onFirstPage())
                    <span>&laquo;</span>
                @else
                    <a href="{{ $rows->previousPageUrl() }}">&laquo;</a>
                @endif

                @php
                    $start = max(1, $rows->currentPage() - 2);
                    $end   = min($rows->lastPage(), $rows->currentPage() + 2);
                @endphp
                @if ($start > 1) <a href="{{ $rows->url(1) }}">1</a> @if ($start > 2) <span>…</span> @endif @endif
                @for ($p = $start; $p <= $end; $p++)
                    @if ($p === $rows->currentPage())
                        <span class="active">{{ $p }}</span>
                    @else
                        <a href="{{ $rows->url($p) }}">{{ $p }}</a>
                    @endif
                @endfor
                @if ($end < $rows->lastPage()) @if ($end < $rows->lastPage() - 1) <span>…</span> @endif <a href="{{ $rows->url($rows->lastPage()) }}">{{ $rows->lastPage() }}</a> @endif

                @if ($rows->hasMorePages())
                    <a href="{{ $rows->nextPageUrl() }}">&raquo;</a>
                @else
                    <span>&raquo;</span>
                @endif
            </div>
        </div>
        @endif
    </div>
</main>
<footer class="app-footer">
    <strong>{{ $config['system']['companyName'] ?? 'PT Coal Mining & Resources' }}</strong>
    • Data Tampungan MSD — {{ $entityDef['entity'] }}
    <span>Tabel: {{ $viewConfig['table'] }}</span>
</footer>
</body>
</html>
