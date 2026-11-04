<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_keeps_a_single_active_admin_with_a_bcrypt_password(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::sole();

        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->active);
        $this->assertSame('bcrypt', password_get_info($admin->password)['algoName']);
    }
}
