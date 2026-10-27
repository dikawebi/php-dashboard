<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LegacyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegacyDataSeeder::class);
    }

    public function test_administrator_can_open_setup(): void
    {
        $admin = User::factory()->create(['role' => 'administrator', 'is_active' => true]);

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Formula Inventory Moving Average');
    }

    public function test_viewer_cannot_open_administrator_setup_or_change_inventory(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $this->actingAs($viewer)->get('/admin/settings')->assertForbidden();
        $this->actingAs($viewer)->post('/inventory')->assertForbidden();
    }

    public function test_user_can_login_with_active_account(): void
    {
        $user = User::factory()->create(['password' => 'secret-password', 'role' => 'viewer', 'is_active' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect(route('dashboard'));
    }
}
