<?php

namespace App\Http\Controllers;

use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class D365SyncController extends Controller
{
    public function __construct(
        private readonly D365SyncService $sync,
        private readonly DashboardService $dashboard,
    ) {}

    /** Tombol "Tarik Data" di tab Integration. entity=all atau key entity. */
    public function sync(Request $request): RedirectResponse
    {
        if (! $this->sync->client()->enabled()) {
            return back()->withErrors(['d365' => 'Konfigurasi D365 belum lengkap (secret/app registration). Lengkapi di halaman Setup Admin terlebih dulu.']);
        }

        $entityKey = $request->string('entity', 'all')->toString();

        try {
            $summary = $entityKey === 'all'
                ? $this->sync->syncAll()
                : [$this->sync->syncEntity($this->sync->entityByKey($entityKey) ?? throw new \InvalidArgumentException('Entity tidak dikenal: '.$entityKey))];

            $lines = collect($summary)->map(static fn ($row) => sprintf(
                '%s: %s baru, %s update, %s ditarik%s',
                $row['entity']['key'], $row['inserted'] ?? 0, $row['updated'] ?? 0, $row['fetched'] ?? 0,
                isset($row['error']) ? ' — gagal: '.$row['error'] : '',
            ))->implode('; ');

            return back()->with('success', 'Sync D365 selesai — '.$lines);
        } catch (\Throwable $exception) {
            return back()->withErrors(['d365' => 'Sync D365 gagal: '.$exception->getMessage()]);
        }
    }

    /** Simpan kredensial & parameter D365 dari halaman Setup Admin. */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'd365_enabled' => ['nullable', 'boolean'],
            'd365_environment_url' => ['required', 'url'],
            'd365_tenant_id' => ['required', 'string', 'uuid'],
            'd365_client_id' => ['required', 'string', 'uuid'],
            'd365_client_secret' => ['nullable', 'string'], // kosong = gunakan nilai terpasang saat ini
            'd365_company' => ['nullable', 'string', 'max:16'],
            'd365_sync_interval' => ['required', 'integer', 'min:1', 'max:1440'],
            'd365_auto_sync' => ['nullable', 'boolean'],
        ]);

        $config = $this->dashboard->settings();
        $config['d365Config'] = [
            'enabled' => $request->boolean('d365_enabled'),
            'environmentUrl' => rtrim($data['d365_environment_url'], '/'),
            'tenantId' => $data['d365_tenant_id'],
            'clientId' => $data['d365_client_id'],
            'clientSecret' => filled($data['d365_client_secret'])
                ? $data['d365_client_secret']
                : ($config['d365Config']['clientSecret'] ?? ''),
            'company' => strtoupper(trim((string) ($data['d365_company'] ?? 'BP'))) ?: 'BP',
            'syncIntervalMinutes' => (int) $data['d365_sync_interval'],
            'autoSync' => $request->boolean('d365_auto_sync'),
        ];
        $this->dashboard->saveSettings($config);

        return back()->with('success', 'Kredensial D365 berhasil disimpan.');
    }
}
