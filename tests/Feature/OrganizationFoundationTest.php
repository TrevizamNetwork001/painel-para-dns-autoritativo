<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_organization_can_be_seeded(): void
    {
        $this->seed();

        $this->assertDatabaseHas('organizations', [
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    public function test_user_can_belong_to_an_organization_with_a_role(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->assertTrue(
            $user->belongsToOrganization($organization)
        );

        $this->assertSame(
            'organization_admin',
            $user->roleForOrganization($organization)
        );
    }

    public function test_inactive_membership_is_not_considered_accessible(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Empresa Bloqueada',
            'slug' => 'empresa-bloqueada',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $user->organizations()->attach($organization->id, [
            'role' => 'viewer',
            'status' => 'inactive',
        ]);

        $this->assertFalse(
            $user->belongsToOrganization($organization)
        );
    }
}
