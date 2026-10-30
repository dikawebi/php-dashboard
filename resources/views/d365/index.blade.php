<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data MSD (D365) — Tampungan Staging</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <style>
        .d365-index-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }
        .entity-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.25rem; display: flex; flex-direction: column; gap: .5rem; }
        .entity-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
        .entity-label { font-weight: 700; font-size: .9rem; color: var(--text-main); }
        .entity-entity { font-size: .74rem; color: var(--text-muted); font-family: var(--font-mono); margin-top: .15rem; }
        .entity-mode { font-size: .72rem; padding: .15rem .5rem; border-radius: 100px; font-weight: 600; }
        .entity-mode.incremental { background: var(--accent-val-light); color: var(--accent-val-dark); }
        .entity-mode.snapshot    { background: var(--accent-cost-light); color: var(--accent-cost-dark); }
        .entity-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; }
        .entity-stat { background: var(--bg-surface); border-radius: 6px; padding: .5rem .75rem; }
        .entity-stat-label { font-size: .7rem; color: var(--text-muted); }
        .entity-stat-value { font-size: 1.05rem; font-weight: 700; color: var(--text-main); }
        .entity-status { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--text-muted); }
        .badge-ok  { color: var(--success); }
        .badge-err { color: var(--danger); }
        .badge-run { color: var(--accent-cost); }
        .entity-actions { display: flex; gap: .5rem; margin-top: .25rem; }
        .entity-actions .btn { font-size: .78rem; padding: .3rem .8rem; }
        .page-header-d365 { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .page-header-d365 h2 { margin:0; font-size:1.35rem; }
    </style>
</head>
<body>
<header class="app-header">
    <div class="brand">
        <img src="{{ asset($config['branding']['logoPath'] ?? 'images/logo-bp.svg') }}" alt="Logo" class="brand-logo">
        <div class="brand-info">
            <h1>{{ $config['system']['companyName'] ?? 'PT COAL MINING & RESOURCES' }}</h1>
            <p>Data Tampungan MSD Dynamics 365</p>
        </div>
    </div>
    <div class="header-status">
        <a class="btn btn-outline" href="{{ route('dashboard', ['tab' => 'integration']) }}">← Dashboard</a>
        @if (auth()->user()->isAdministrator())
            <a class="btn btn-outline" href="{{ route('admin.settings') }}">⚙️ Setup Admin</a>
        @endif
        <strong>{{ auth()->user()->name }} ({{ ucfirst(auth()->user()->role) }})</strong>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline">Keluar</button></form>
    </div>
</header>

<main class="main-content">
    @if (session('success')) <div class="notice">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="notice notice-error">{{ $errors->first() }}</div> @endif

    <div class="page-header-d365">
        <div>
            <h2>📦 Data Tampungan — Microsoft Dynamics 365</h2>
            <p style="color:var(--text-muted);margin:0">Hasil tarikan OData per entity. Klik entity untuk browse isi tabelnya.</p>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <a class="btn btn-outline" href="{{ route('d365.data.staging') }}">📋 Staging Format CSV</a>
            <a class="btn btn-outline" href="{{ route('d365.data.main_account_summary') }}">∑ Cek Sum Main Account</a>
            @if (in_array(auth()->user()->role, ['administrator', 'accountant'], true))
                <form action="{{ route('d365.sync') }}" method="post">
                    @csrf<input type="hidden" name="entity" value="all">
                    <button class="btn btn-primary">🔄 Tarik Semua Entity</button>
                </form>
            @endif
        </div>
    </div>

    <div class="d365-index-grid">
        @foreach ($statuses as $entity)
        @php
            $last   = $entity['last'];
            $count  = $entity['staging_count'] ?? 0;
            $typed  = $entity['typed_count'] ?? null;
            $status = $last?->status ?? null;
        @endphp
        <div class="entity-card">
            <div class="entity-card-head">
                <div>
                    <div class="entity-label">{{ $entity['label'] }}</div>
                    <div class="entity-entity">{{ $entity['entity'] }}</div>
                </div>
                <span class="entity-mode {{ $entity['mode'] }}">
                    {{ $entity['mode'] === 'incremental' ? 'Incremental' : 'Snapshot' }}
                </span>
            </div>

            <div class="entity-stats">
                <div class="entity-stat">
                    <div class="entity-stat-label">Raw Staging (JSON)</div>
                    <div class="entity-stat-value">{{ number_format($count, 0, ',', '.') }}</div>
                </div>
                <div class="entity-stat">
                    <div class="entity-stat-label">Typed Staging (d365s_*)</div>
                    <div class="entity-stat-value">
                        {{ $typed !== null ? number_format($typed, 0, ',', '.') : '—' }}
                    </div>
                </div>
            </div>

            <div class="entity-status">
                @if ($status === 'success')
                    <span class="badge-ok">✅</span> Sukses
                @elseif ($status === 'failed')
                    <span class="badge-err">❌</span> Gagal
                @elseif ($status === 'running')
                    <span class="badge-run">⏳</span> Running
                @else
                    <span>—</span> Belum pernah sync
                @endif
                @if ($last)
                    &nbsp;·&nbsp;{{ $last->updated_at }}
                    &nbsp;·&nbsp;{{ number_format($last->fetched, 0, ',', '.') }} baris ditarik
                @endif
            </div>

            @if ($last?->message)
                <small style="color:var(--danger);word-break:break-all">{{ $last->message }}</small>
            @endif

            <div class="entity-actions">
                @if ($typed !== null)
                    <a class="btn btn-primary" href="{{ route('d365.data.show', $entity['key']) }}">
                        🔍 Browse Data
                    </a>
                @else
                    <span class="btn btn-outline" style="opacity:.5;cursor:default" title="Belum ada typed staging">Browse Data</span>
                @endif
                @if (in_array(auth()->user()->role, ['administrator', 'accountant'], true))
                    <form action="{{ route('d365.sync') }}" method="post">
                        @csrf<input type="hidden" name="entity" value="{{ $entity['key'] }}">
                        <button class="btn btn-outline">↻ Tarik</button>
                    </form>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</main>
<footer class="app-footer">
    <strong>{{ $config['system']['companyName'] ?? 'PT Coal Mining & Resources' }}</strong>
    • Data Tampungan MSD Dynamics 365 <span>d365s_* Typed Staging Tables</span>
</footer>
</body>
</html>
