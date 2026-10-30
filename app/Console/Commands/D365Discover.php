<?php

namespace App\Console\Commands;

use App\Services\D365\D365SyncService;
use Illuminate\Console\Command;

class D365Discover extends Command
{
    protected $signature = 'd365:discover
        {--entity=all : Key entity tunggal, atau "all" untuk semua entity}';

    protected $description = 'Tampilkan field yang benar-benar dikembalikan oleh entity OData D365';

    public function handle(D365SyncService $sync): int
    {
        $client = $sync->client();
        if (! $client->enabled()) {
            $this->error('Konfigurasi D365 belum lengkap/aktif.');

            return self::FAILURE;
        }

        $requested = (string) $this->option('entity');
        $entities = $requested === 'all'
            ? D365SyncService::ENTITIES
            : array_values(array_filter(D365SyncService::ENTITIES, fn (array $entity) => $entity['key'] === $requested));

        if ($entities === []) {
            $this->error('Entity tidak dikenal: '.$requested);

            return self::FAILURE;
        }

        foreach ($entities as $entity) {
            $found = false;
            try {
                foreach ($client->streamEntity($entity['entity'], select: null, filter: null, top: 1, maxPages: 1) as $row) {
                    $this->line($entity['key'].': '.implode(', ', array_keys($row)));
                    $found = true;
                    break;
                }
                if (! $found) {
                    $this->line($entity['key'].': NO_ROWS');
                }
            } catch (\Throwable $exception) {
                $this->error($entity['key'].': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
