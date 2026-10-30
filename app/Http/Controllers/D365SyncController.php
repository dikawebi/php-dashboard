<?php

namespace App\Http\Controllers;

use App\Jobs\RunD365Sync;
use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class D365SyncController extends Controller
{
    public function __construct(
        private readonly D365SyncService $sync,
        private readonly DashboardService $dashboard,
    ) {}

    /** Tombol "Tarik Data" di tab Integration. entity=all atau key entity. */
    public function sync(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->sync->client()->enabled()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Konfigurasi D365 belum lengkap.'], 422);
            }

            return back()->withErrors(['d365' => 'Konfigurasi D365 belum lengkap (secret/app registration). Lengkapi di halaman Setup Admin terlebih dulu.']);
        }

        $entityKey = $request->string('entity', 'all')->toString();
        if ($entityKey !== 'all' && $this->sync->entityByKey($entityKey) === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Entity tidak dikenal: '.$entityKey], 422);
            }

            return back()->withErrors(['d365' => 'Entity tidak dikenal: '.$entityKey]);
        }

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        if (filled($dateFrom) xor filled($dateTo)) {
            return response()->json(['message' => 'date_from dan date_to harus diisi bersama.'], 422);
        }

        if ($entityKey === 'all') {
            if (filled($dateFrom) && filled($dateTo)) {
                $ordered = [
                    'main_accounts', 'dimension_values', 'vendors', 'customers',
                    'gl_journal_entries', 'ledger_journal_lines',
                    'inventory_trans', 'gl_entries',
                    'ledger_entry_journals',
                ];
                Bus::chain(array_map(
                    fn (string $key) => new RunD365Sync($key, $dateFrom, $dateTo),
                    $ordered,
                ))->dispatch();
            } else {
                foreach (D365SyncService::ENTITIES as $entity) {
                    RunD365Sync::dispatch($entity['key'], $dateFrom, $dateTo);
                }
            }
        } else {
            RunD365Sync::dispatch($entityKey, $dateFrom, $dateTo);
        }

        if ($request->expectsJson()) {
            return response()->json(['queued' => true, 'entity' => $entityKey]);
        }

        return back()->with('success', 'Sync D365 dimasukkan ke antrean. Progress dapat dipantau pada tabel entity.');
    }

    public function progress(): JsonResponse
    {
        $rows = $this->sync->latestStatus()->map(static function (array $entity): array {
            $last = $entity['last'];

            return [
                'key' => $entity['key'],
                'status' => $last?->status ?? 'pending',
                'fetched' => (int) ($last?->fetched ?? 0),
                'total' => $last?->total !== null ? (int) $last->total : null,
                'progress' => (int) ($last?->progress_percent ?? 0),
                'message' => $last?->message,
                'updated_at' => $last?->updated_at,
            ];
        })->values();

        return response()->json([
            'rows' => $rows,
            'queued' => (int) DB::table('jobs')->count(),
        ]);
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
