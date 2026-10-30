<?php

namespace App\Console\Commands;

use App\Services\D365\D365SyncService;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class D365Sync extends Command
{
    protected $signature = 'd365:sync
        {--entity=all : Key entity tunggal, atau "all" untuk semua entity}
        {--limit= : Batasi jumlah record per entity untuk proofing}
        {--scheduled : Dipanggil scheduler — patuhi interval & autoSync}';

    protected $description = 'Tarik data dari data entity OData D365 ke tabel staging MySQL';

    public function handle(D365SyncService $sync): int
    {
        if (! $sync->client()->enabled()) {
            $this->info('Konfigurasi D365 belum lengkap/aktif — skip.');

            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            $settings = app(DashboardService::class)->settings();
            $interval = (int) data_get($settings, 'd365Config.syncIntervalMinutes', 15);
            $autoSync = (bool) data_get($settings, 'd365Config.autoSync', false);

            if (! $autoSync) {
                $this->line('autoSync dimatikan di config — skip.');

                return self::SUCCESS;
            }

            $lastAt = DB::table('d365_sync_log')->where('status', 'success')->max('updated_at');
            if ($lastAt !== null && Carbon::parse($lastAt)->diffInMinutes(now()) < max(1, $interval)) {
                $this->line('Interval belum tercapai (tiap '.max(1, $interval)." menit) — skip. Sync sukses terakhir: {$lastAt}.");

                return self::SUCCESS;
            }
        }

        $entityKey = (string) $this->option('entity');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $entity = $entityKey !== 'all' ? ($sync->entityByKey($entityKey) ?? throw new \InvalidArgumentException("Entity tidak dikenal: {$entityKey}")) : null;
        $summary = $entity !== null ? [$sync->syncEntity($entity, $limit)] : $sync->syncAll($limit);

        foreach ($summary as $row) {
            $line = sprintf(
                '[%s] %s -> tarik %s, baru %s, update %s',
                $row['status'],
                $row['entity']['key'],
                $row['fetched'] ?? 0,
                $row['inserted'] ?? 0,
                $row['updated'] ?? 0,
            );
            if ($row['status'] === 'success') {
                $this->info($line);
            } else {
                $this->error($line.' | '.($row['error'] ?? 'gagal'));
            }
        }

        return self::SUCCESS;
    }
}
