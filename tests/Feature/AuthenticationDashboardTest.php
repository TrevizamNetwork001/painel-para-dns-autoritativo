<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_to_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Entrar em DNS Center')
            ->assertSee('DNS Center');
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_authenticated_member_can_access_dashboard(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trevizam Network')
            ->assertSee($user->name);
    }

    public function test_user_without_organization_context_is_forbidden(): void
    {
        $user = User::factory()->create([
            'current_organization_id' => null,
            'is_platform_admin' => false,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }
}
