<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegacyDataSeeder extends Seeder
{
    public function run(): void
    {
        $seedPath = database_path('seed-data');
        $definitions = json_decode(file_get_contents($seedPath.'/cost_matrix_definition.json'), true, 512, JSON_THROW_ON_ERROR);
        $glEntries = json_decode(file_get_contents($seedPath.'/gl_data.json'), true, 512, JSON_THROW_ON_ERROR);
        $tickets = json_decode(file_get_contents($seedPath.'/production_data.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = json_decode(file_get_contents($seedPath.'/config.json'), true, 512, JSON_THROW_ON_ERROR);

        DB::table('dashboard_settings')->updateOrInsert(
            ['setting_key' => 'config'],
            ['setting_value' => json_encode($config, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]
        );

        DB::table('cost_matrix_definitions')->delete();
        foreach (array_chunk($definitions, 200) as $chunk) {
            DB::table('cost_matrix_definitions')->insert(array_map(fn (array $item) => [
                'code' => $item['code'], 'name' => $item['name'], 'parent_code' => $item['parent'] ?? null,
                'is_parent' => (bool) ($item['isParent'] ?? false),
                'val_mining' => $item['valMining'] ?? 0, 'val_hauling' => $item['valHauling'] ?? 0,
                'val_wp' => $item['valWP'] ?? 0, 'val_port' => $item['valPort'] ?? 0,
                'cost_mining' => $item['costMining'] ?? 0, 'cost_hauling' => $item['costHauling'] ?? 0,
                'cost_wp' => $item['costWP'] ?? 0, 'cost_port' => $item['costPort'] ?? 0,
                'cost_ga' => $item['costGA'] ?? 0, 'cost_selling' => $item['costSelling'] ?? 0,
                'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }

        DB::table('gl_entries')->delete();
        foreach (array_chunk($glEntries, 200) as $chunk) {
            DB::table('gl_entries')->insert(array_map(function (array $item): array {
                $date = $item['Date'] ?? null;

                return [
                    'journal_number' => $item['Journal number'] ?? null, 'voucher' => $item['Voucher'] ?? null,
                    'entry_date' => $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
                    'account_name' => $item['AccountName'] ?? $item['Account Name'] ?? null,
                    'main_account' => (string) ($item['MainAccount'] ?? $item['AccountCode'] ?? ''),
                    'amount_idr' => $item['Amount IDR'] ?? $item['Amount'] ?? 0,
                    'valuation_to' => $item['Valuation to'] ?? $item['Valuation_to'] ?? null,
                    'costing_to' => $item['Costing to'] ?? $item['Costing_to'] ?? null,
                    'source_payload' => json_encode($item, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ];
            }, $chunk));
        }

        DB::table('production_tickets')->delete();
        foreach (array_chunk($tickets, 200) as $chunk) {
            DB::table('production_tickets')->insert(array_map(function (array $item): array {
                $date = $item['Tgl Keluar'] ?? null;

                return [
                    'exit_date' => $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
                    'exit_time' => $item['Jam Keluar'] ?? null, 'product' => $item['Product'] ?? null,
                    'truck_number' => $item['No Pol'] ?? null, 'driver' => $item['Sopir'] ?? null,
                    'gross_kg' => $item['BRUTO'] ?? 0, 'tare_kg' => $item['TARA'] ?? 0, 'net_kg' => $item['NETTO'] ?? 0,
                    'weighbridge' => $item['WEIGHBRIDGE'] ?? null, 'supplier' => $item['Suplier'] ?? $item['Supplier'] ?? null,
                    'operator' => $item['Operator'] ?? null, 'source_payload' => json_encode($item, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }, $chunk));
        }
    }
}
