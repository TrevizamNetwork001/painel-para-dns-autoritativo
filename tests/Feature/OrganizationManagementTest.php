<?php

namespace Tests\Feature;

use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_organization_with_first_admin_user(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAs($platformAdmin)
            ->post('/empresas', [
                'organization_name' => 'Cliente Alpha',
                'name' => 'Admin Alpha',
                'email' => 'admin@clientealpha.test',
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
            ])
            ->assertRedirect('/empresas');

        $organization = Organization::query()
            ->where('slug', 'cliente-alpha')
            ->sole();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@clientealpha.test',
            'status' => 'active',
            'is_platform_admin' => false,
            'must_change_password' => true,
            'current_organization_id' => $organization->id,
        ]);

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    public function test_non_platform_admin_cannot_manage_organizations(): void
    {
        $organizationAdmin = $this->makeNonPlatformOrgAdmin();

        $this->actingAs($organizationAdmin)
            ->get('/empresas')
            ->assertForbidden();

        $this->actingAs($organizationAdmin)
            ->post('/empresas', [
                'organization_name' => 'Nao Deveria Existir',
                'name' => 'Teste',
                'email' => 'teste@example.com',
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('organizations', [
            'name' => 'Nao Deveria Existir',
        ]);
    }

    public function test_duplicate_organization_name_is_rejected_with_validation_error(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        Organization::factory()->create([
            'name' => 'Cliente Beta',
            'slug' => 'cliente-beta',
        ]);

        $this->actingAs($platformAdmin)
            ->post('/empresas', [
                'organization_name' => 'Cliente Beta',
                'name' => 'Outro Admin',
                'email' => 'outro@clientebeta.test',
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
            ])
            ->assertSessionHasErrors('organization_name');

        $this->assertDatabaseMissing('users', [
            'email' => 'outro@clientebeta.test',
        ]);
    }

    public function test_duplicate_email_for_first_user_is_rejected(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        User::factory()->create(['email' => 'ja-existe@example.com']);

        $this->actingAs($platformAdmin)
            ->post('/empresas', [
                'organization_name' => 'Cliente Gama',
                'name' => 'Admin Gama',
                'email' => 'ja-existe@example.com',
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('organizations', [
            'slug' => 'cliente-gama',
        ]);
    }

    public function test_newly_created_user_can_log_in_and_is_scoped_to_own_organization(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAs($platformAdmin)
            ->post('/empresas', [
                'organization_name' => 'Cliente Delta',
                'name' => 'Admin Delta',
                'email' => 'admin@clientedelta.test',
                'password' => 'senha1234',
                'password_confirmation' => 'senha1234',
            ])
            ->assertRedirect('/empresas');

        $newUser = User::query()
            ->where('email', 'admin@clientedelta.test')
            ->sole();

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'admin@clientedelta.test',
            'password' => 'senha1234',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($newUser);

        $newUser->forceFill(['must_change_password' => false])->save();

        $this->actingAs($newUser->fresh())
            ->get('/dashboard')
            ->assertOk();

        $foreignServer = DnsServer::factory()->create();

        $this->actingAs($newUser->fresh())
            ->patch(route('servers.status', $foreignServer))
            ->assertNotFound();
    }

    private function makePlatformAdmin(): User
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

        return $admin;
    }

    private function makeNonPlatformOrgAdmin(): User
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => false,
            'status' => 'active',
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }
}
