<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Menanam kredensial sandbox BPIMR yang sudah diverifikasi ke dashboard_settings.
 * Untuk produksi, jalankan sekali dengan nilai asli production (ubah konstanta di bawah).
 */
class D365ConfigSeeder extends Seeder
{
    private const SANDBOX = [
        'enabled' => true,
        'environmentUrl' => 'https://bpimr-test.sandbox.operations.dynamics.com',
        'tenantId' => '66d580b6-ca62-4359-be90-04b074f71e95',
        'clientId' => '58d3316a-b554-4e0e-8977-18f312579a05',
        'clientSecret' => '', // diisi di bawah oleh seeder dari sumber runtime
        'syncIntervalMinutes' => 15,
        'autoSync' => true,
    ];

    public function run(): void
    {
        $secret = rtrim((string) getenv('D365_CLIENT_SECRET'), "\r\n");

        if ($secret === '') {
            $this->command->warn('D365_CLIENT_SECRET tidak diset di environment — kredensial ditanam dengan secret kosong. Isi lewat halaman Setup Admin.');

            return;
        }

        self::apply($secret);

        $this->command->info('Kredensial D365 sandbox berhasil ditanam ke dashboard_settings.');
    }

    /** Tulis kredensial ke tabel dashboard_settings (helper untuk tooling). */
    public static function apply(string $clientSecret): void
    {
        $settings = DB::table('dashboard_settings')->where('setting_key', 'config')->value('setting_value');
        $config = $settings ? (json_decode($settings, true) ?: []) : [];
        $config['d365Config'] = array_merge(
            $config['d365Config'] ?? [],
            self::SANDBOX,
            ['clientSecret' => $clientSecret],
        );

        $updated = DB::table('dashboard_settings')->where('setting_key', 'config')->update([
            'setting_value' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            DB::table('dashboard_settings')->insert([
                'setting_key' => 'config',
                'setting_value' => json_encode($config, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
