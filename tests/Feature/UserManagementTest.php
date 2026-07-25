<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_open_users_page(): void
    {
        [$organization, $admin] = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee($organization->name)
            ->assertSee($admin->email);
    }

    public function test_admin_can_create_organization_user(): void
    {
        [, $admin] = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/usuarios', [
                'name' => 'Operador DNS',
                'email' => 'operador@example.com',
                'password' => 'SenhaForte@2026',
                'password_confirmation' => 'SenhaForte@2026',
                'role' => 'operator',
            ])
            ->assertRedirect('/usuarios');

        $this->assertDatabaseHas('users', [
            'email' => 'operador@example.com',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('organization_user', [
            'role' => 'operator',
            'status' => 'active',
        ]);
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        [, $admin] = $this->makeAdmin();

        $this->actingAs($admin)
            ->patch("/usuarios/{$admin->id}/status")
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'status' => 'active',
        ]);
    }

    private function makeAdmin(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => true,
            'status' => 'active',
        ]);

        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$organization, $admin];
    }
}
