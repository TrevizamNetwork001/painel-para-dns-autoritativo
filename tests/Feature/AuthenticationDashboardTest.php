<?php

namespace Tests\Feature;

use App\Models\DnsZone;
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
            ->assertSee($user->name)
            ->assertSee(route('servers.index'), false)
            ->assertSee(route('nameservers.index'), false)
            ->assertSee(route('zones.index'), false)
            ->assertSee(route('users.index'), false);
    }

    public function test_dashboard_counts_only_zones_from_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        foreach ([$organization, $otherOrganization] as $owner) {
            DnsZone::query()->create([
                'organization_id' => $owner->id,
                'name' => $owner->slug.'.example',
                'kind' => 'primary',
                'serial' => 2026072901,
                'soa_mname' => 'ns1.example.net',
                'soa_rname' => 'hostmaster.example.net',
            ]);
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(
                'data-dashboard-zone-count',
                false,
            )
            ->assertSeeInOrder([
                'data-dashboard-zone-count',
                '1',
                'Zonas autoritativas',
            ], false);
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
