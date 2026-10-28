<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\D365\D365SyncService;
use Database\Seeders\LegacyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPeriodAndD365FilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegacyDataSeeder::class);
    }

    public function test_dashboard_can_switch_periods_mtd_ytd_all(): void
    {
        $user = User::factory()->create(['role' => 'administrator', 'is_active' => true]);

        // MTD
        $responseMtd = $this->actingAs($user)->get('/?period_year=2026&period_month=10&period_view=mtd');
        $responseMtd->assertOk();
        $responseMtd->assertSee('Oktober 2026 (MTD)');

        // YTD
        $responseYtd = $this->actingAs($user)->get('/?period_year=2026&period_month=10&period_view=ytd');
        $responseYtd->assertOk();
        $responseYtd->assertSee('Oktober 2026 (YTD)');

        // All Period
        $responseAll = $this->actingAs($user)->get('/?period_year=2026&period_month=10&period_view=all');
        $responseAll->assertOk();
        $responseAll->assertSee('Oktober 2026');
    }

    public function test_d365_sync_service_entities_have_company_filter_and_select_columns(): void
    {
        $syncService = app(D365SyncService::class);
        $this->assertSame('BP', $syncService->targetCompany());

        $glEntity = $syncService->entityByKey('gl_entries');
        $this->assertNotNull($glEntity);
        $this->assertSame('SubledgerVoucherDataAreaId', $glEntity['dataAreaField']);
        $this->assertContains('SubledgerVoucherDataAreaId', $glEntity['select']);
        $this->assertContains('MainAccount', $glEntity['select']);
        $this->assertContains('AccountingCurrencyAmount', $glEntity['select']);

        $vendorEntity = $syncService->entityByKey('vendors');
        $this->assertNotNull($vendorEntity);
        $this->assertSame('dataAreaId', $vendorEntity['dataAreaField']);
        $this->assertContains('VendorAccountNumber', $vendorEntity['select']);

        $poEntity = $syncService->entityByKey('po_headers');
        $this->assertNotNull($poEntity);
        $this->assertSame('dataAreaId', $poEntity['dataAreaField']);
        $this->assertContains('PurchaseOrderNumber', $poEntity['select']);
    }
}
