<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDemoData;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_manage_allocations_gate_allows_only_admin(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('manage-allocations'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('manage-allocations'));
    }

    public function test_demo_users_lists_admin_first_with_role(): void
    {
        $customer = User::factory()->create(['name' => 'Alice']);
        $admin = User::factory()->admin()->create(['name' => 'Alicia']);

        $res = $this->getJson('/api/demo/users')->assertOk();
        $this->assertSame([$admin->id, $customer->id], array_column($res->json('data'), 'id'));
        $this->assertSame(['admin', 'customer'], array_column($res->json('data'), 'role'));

        $res = $this->getJson('/api/demo/users?q=ali')->assertOk();
        $this->assertSame([$admin->id, $customer->id], array_column($res->json('data'), 'id'));
    }

    public function test_me_includes_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->getJson('/api/me', ['X-Demo-User-Id' => (string) $admin->id])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $created = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'x', 'role' => 'admin']);
        $this->assertSame(UserRole::Customer, $created->fresh()->role);

        $filled = User::factory()->create()->fill(['role' => 'admin']);
        $this->assertFalse($filled->isAdmin());
    }

    public function test_admin_seeder_is_idempotent(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@penusta.example')->count());
        $this->assertSame(UserRole::Admin, User::where('email', 'admin@penusta.example')->first()->role);
    }

    public function test_admin_seeder_promotes_existing_user(): void
    {
        User::create(['name' => 'X', 'email' => 'admin@penusta.example', 'password' => 'x']);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(User::where('email', 'admin@penusta.example')->first()->isAdmin());
    }

    public function test_demo_data_cleanup_keeps_admin_and_test_user(): void
    {
        $admin = User::factory()->admin()->create();
        $test = User::factory()->create(['email' => 'test@example.com']);
        $other = User::factory()->create();

        $command = app(GenerateDemoData::class);
        (new \ReflectionMethod($command, 'cleanup'))->invoke($command);

        $this->assertNotNull(User::find($admin->id));
        $this->assertNotNull(User::find($test->id));
        $this->assertNull(User::find($other->id));
    }
}
