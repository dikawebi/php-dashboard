<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LegacyDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegacyDataSeeder::class);
    }

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'administrator', 'is_active' => true]));
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
