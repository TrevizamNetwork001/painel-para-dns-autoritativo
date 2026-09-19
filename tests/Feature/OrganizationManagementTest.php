<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAuditLog;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_platform_admin_can_deactivate_and_reactivate_organization(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $organization = Organization::factory()->create(['status' => 'active']);

        $this->actingAs($platformAdmin)
            ->patch(route('organizations.status', $organization))
            ->assertRedirect();

        $this->assertSame('inactive', $organization->fresh()->status);

        $this->actingAs($platformAdmin)
            ->patch(route('organizations.status', $organization))
            ->assertRedirect();

        $this->assertSame('active', $organization->fresh()->status);
    }

    public function test_deactivated_organization_blocks_member_access(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $member = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);
        $member->organizations()->attach($organization->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);

        $this->actingAs($member)->get('/dashboard')->assertOk();

        $organization->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($member->fresh())
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_non_platform_admin_cannot_update_organization_status(): void
    {
        $organizationAdmin = $this->makeNonPlatformOrgAdmin();
        $target = Organization::factory()->create(['status' => 'active']);

        $this->actingAs($organizationAdmin)
            ->patch(route('organizations.status', $target))
            ->assertForbidden();

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_default_organization_cannot_be_deactivated_or_deleted(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $default = Organization::query()->where('is_default', true)->sole();

        $this->actingAs($platformAdmin)
            ->patch(route('organizations.status', $default))
            ->assertStatus(409);

        $this->actingAs($platformAdmin)
            ->delete(route('organizations.destroy', $default), ['confirmation' => $default->name])
            ->assertStatus(409);

        $this->assertDatabaseHas('organizations', ['id' => $default->id]);
    }

    public function test_platform_admin_can_delete_organization_with_correct_confirmation(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $organization = Organization::factory()->create(['name' => 'Cliente Cancelado']);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'ipv4_address' => '203.0.113.42',
        ]);
        $tokenHash = hash('sha256', 'deleted-agent-token');
        DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => 'deleted-agent',
            'token_hash' => $tokenHash,
            'registered_at' => now(),
        ]);
        $onlyMember = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => false,
        ]);
        $onlyMember->organizations()->attach($organization->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);
        $dnsAudit = DnsAuditLog::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $onlyMember->id,
            'actor' => $onlyMember->email,
            'action' => 'zone.created',
            'domain' => 'cliente-cancelado.test',
            'record_name' => 'Cliente Cancelado',
        ]);
        $securityAudit = SecurityAudit::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $onlyMember->id,
            'event' => 'auth.login.succeeded',
            'actor' => 'user:'.$onlyMember->id,
            'source' => 'web',
            'result' => 'success',
            'reason' => 'Cliente Cancelado',
        ]);

        $this->actingAs($platformAdmin)
            ->delete(route('organizations.destroy', $organization), [
                'confirmation' => 'Cliente Cancelado',
            ])
            ->assertRedirect(route('organizations.index'));

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
        $this->assertDatabaseMissing('dns_servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('users', ['id' => $onlyMember->id]);
        $this->assertDatabaseMissing('dns_audit_logs', ['id' => $dnsAudit->id]);
        $this->assertDatabaseMissing('security_audits', ['id' => $securityAudit->id]);
        $this->assertDatabaseMissing('dns_audit_logs', ['record_name' => 'Cliente Cancelado']);
        $this->assertDatabaseHas('blocked_agent_sources', [
            'token_hash' => $tokenHash,
            'reason' => 'organization_deleted',
        ]);
        $this->assertDatabaseHas('blocked_agent_sources', [
            'ip_address' => '203.0.113.42',
            'reason' => 'organization_deleted',
        ]);
    }

    public function test_delete_requires_exact_name_confirmation(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $organization = Organization::factory()->create(['name' => 'Cliente Exato']);

        $this->actingAs($platformAdmin)
            ->delete(route('organizations.destroy', $organization), [
                'confirmation' => 'cliente exato',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_delete_preserves_user_who_belongs_to_another_organization(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $organization = Organization::factory()->create(['name' => 'Empresa Um']);
        $otherOrganization = Organization::factory()->create();

        $sharedUser = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => false,
        ]);
        $sharedUser->organizations()->attach($organization->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);
        $sharedUser->organizations()->attach($otherOrganization->id, [
            'role' => 'viewer', 'status' => 'active', 'is_default' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->delete(route('organizations.destroy', $organization), [
                'confirmation' => 'Empresa Um',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $sharedUser->id]);
        $this->assertDatabaseHas('organization_user', [
            'user_id' => $sharedUser->id,
            'organization_id' => $otherOrganization->id,
        ]);
    }

    public function test_non_platform_admin_cannot_delete_organization(): void
    {
        $organizationAdmin = $this->makeNonPlatformOrgAdmin();
        $target = Organization::factory()->create(['name' => 'Alvo Protegido']);

        $this->actingAs($organizationAdmin)
            ->delete(route('organizations.destroy', $target), [
                'confirmation' => 'Alvo Protegido',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $target->id]);
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
