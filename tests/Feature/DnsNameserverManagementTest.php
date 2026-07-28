<?php

namespace Tests\Feature;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsNameserverManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_nameservers_page(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS Produção 01',
            'hostname' => 'ns1.example.net',
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('nameservers.index'))
            ->assertOk()
            ->assertSee('Nameservers')
            ->assertSee('NS Produção 01')
            ->assertSee('ns1.example.net');
    }

    public function test_admin_can_create_external_identity(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $response = $this->actingAs($admin)
            ->post(
                route('nameservers.identity.store'),
                [
                    'name' => 'NS Externo',
                    'hostname' => 'NS1.EXTERNAL.EXAMPLE.',
                    'ipv4_address' => '198.51.100.10',
                    'ipv6_address' => '',
                    'dns_server_id' => '',
                    'notes' => 'DNS de terceiro',
                ],
            );

        $response->assertRedirect(
            route('nameservers.index', [
                'tab' => 'identities',
            ]),
        );

        $this->assertDatabaseHas(
            'dns_nameserver_identities',
            [
                'organization_id' => $organization->id,
                'name' => 'NS Externo',
                'hostname' => 'ns1.external.example',
                'dns_server_id' => null,
                'ipv4_address' => '198.51.100.10',
                'enabled' => true,
            ],
        );
    }

    public function test_identity_can_be_linked_to_server(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($admin)
            ->post(
                route('nameservers.identity.store'),
                [
                    'name' => 'NS Local',
                    'hostname' => 'ns1.local.example',
                    'dns_server_id' => $server->id,
                    'ipv4_address' => '192.0.2.20',
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(
            'dns_nameserver_identities',
            [
                'organization_id' => $organization->id,
                'dns_server_id' => $server->id,
                'hostname' => 'ns1.local.example',
            ],
        );
    }

    public function test_server_from_other_organization_is_rejected(): void
    {
        [$admin] = $this->userWithRole(
            'organization_admin',
        );

        $otherOrganization = Organization::factory()->create();

        $server = DnsServer::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $this->actingAs($admin)
            ->post(
                route('nameservers.identity.store'),
                [
                    'name' => 'NS Inválido',
                    'hostname' => 'ns1.invalid.example',
                    'dns_server_id' => $server->id,
                ],
            )
            ->assertSessionHasErrors('dns_server_id');

        $this->assertDatabaseMissing(
            'dns_nameserver_identities',
            [
                'hostname' => 'ns1.invalid.example',
            ],
        );
    }

    public function test_operator_cannot_create_identity(): void
    {
        [$operator] = $this->userWithRole('operator');

        $this->actingAs($operator)
            ->post(
                route('nameservers.identity.store'),
                [
                    'name' => 'NS Bloqueado',
                    'hostname' => 'ns1.blocked.example',
                ],
            )
            ->assertForbidden();
    }

    public function test_admin_can_update_identity(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $identity = $this->identity(
            $organization,
            'ns1.old.example',
        );

        $this->actingAs($admin)
            ->put(
                route(
                    'nameservers.identity.update',
                    $identity,
                ),
                [
                    'name' => 'NS Atualizado',
                    'hostname' => 'ns1.new.example',
                    'ipv6_address' => '2001:db8::53',
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(
            'dns_nameserver_identities',
            [
                'id' => $identity->id,
                'name' => 'NS Atualizado',
                'hostname' => 'ns1.new.example',
                'ipv6_address' => '2001:db8::53',
            ],
        );
    }

    public function test_admin_cannot_update_identity_from_other_organization(): void
    {
        [$admin] = $this->userWithRole(
            'organization_admin',
        );

        $otherOrganization = Organization::factory()->create();

        $identity = $this->identity(
            $otherOrganization,
            'ns1.other.example',
        );

        $this->actingAs($admin)
            ->put(
                route(
                    'nameservers.identity.update',
                    $identity,
                ),
                [
                    'name' => 'Tentativa',
                    'hostname' => 'ns1.changed.example',
                ],
            )
            ->assertNotFound();
    }

    public function test_admin_can_toggle_identity(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $identity = $this->identity(
            $organization,
            'ns1.toggle.example',
        );

        $this->actingAs($admin)
            ->patch(
                route(
                    'nameservers.identity.status',
                    $identity,
                ),
            )
            ->assertSessionHasNoErrors();

        $this->assertFalse(
            $identity->fresh()->enabled,
        );
    }

    public function test_admin_can_create_profile_with_ordered_identities(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $first = $this->identity(
            $organization,
            'ns1.profile.example',
        );

        $second = $this->identity(
            $organization,
            'ns2.profile.example',
        );

        $this->actingAs($admin)
            ->post(
                route('nameservers.profile.store'),
                [
                    'name' => 'Produção',
                    'identity_ids' => [
                        $second->id,
                        $first->id,
                    ],
                    'is_default' => '1',
                ],
            )
            ->assertSessionHasNoErrors();

        $profile = DnsNameserverProfile::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Produção')
            ->firstOrFail();

        $this->assertTrue($profile->is_default);

        $this->assertSame(
            [
                $second->id,
                $first->id,
            ],
            $profile->identities()
                ->pluck('dns_nameserver_identities.id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );

        $this->assertSame(
            [1, 2],
            $profile->identities()
                ->get()
                ->pluck('pivot.position')
                ->map(fn ($position) => (int) $position)
                ->all(),
        );
    }

    public function test_profile_requires_at_least_two_identities(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $identity = $this->identity(
            $organization,
            'ns1.single.example',
        );

        $this->actingAs($admin)
            ->post(
                route('nameservers.profile.store'),
                [
                    'name' => 'Inválido',
                    'identity_ids' => [
                        $identity->id,
                    ],
                ],
            )
            ->assertSessionHasErrors('identity_ids');
    }

    public function test_profile_rejects_identity_from_other_organization(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $local = $this->identity(
            $organization,
            'ns1.local-profile.example',
        );

        $otherOrganization = Organization::factory()->create();

        $external = $this->identity(
            $otherOrganization,
            'ns2.other-profile.example',
        );

        $this->actingAs($admin)
            ->post(
                route('nameservers.profile.store'),
                [
                    'name' => 'Cruzado',
                    'identity_ids' => [
                        $local->id,
                        $external->id,
                    ],
                ],
            )
            ->assertSessionHasErrors('identity_ids.1');
    }

    public function test_only_one_default_profile_is_kept(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $first = $this->identity(
            $organization,
            'ns1.default.example',
        );

        $second = $this->identity(
            $organization,
            'ns2.default.example',
        );

        $oldProfile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Perfil antigo',
            'is_default' => true,
            'enabled' => true,
        ]);

        $oldProfile->identities()->attach([
            $first->id => [
                'position' => 1,
            ],
            $second->id => [
                'position' => 2,
            ],
        ]);

        $this->actingAs($admin)
            ->post(
                route('nameservers.profile.store'),
                [
                    'name' => 'Perfil novo',
                    'identity_ids' => [
                        $first->id,
                        $second->id,
                    ],
                    'is_default' => '1',
                ],
            )
            ->assertSessionHasNoErrors();

        $this->assertFalse(
            $oldProfile->fresh()->is_default,
        );

        $this->assertDatabaseCount(
            'dns_nameserver_profiles',
            2,
        );

        $this->assertSame(
            1,
            DnsNameserverProfile::query()
                ->forOrganization($organization->id)
                ->where('is_default', true)
                ->count(),
        );
    }

    public function test_disabling_default_profile_removes_default_flag(): void
    {
        [$admin, $organization] = $this->userWithRole(
            'organization_admin',
        );

        $profile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Perfil padrão',
            'is_default' => true,
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->patch(
                route(
                    'nameservers.profile.status',
                    $profile,
                ),
            )
            ->assertSessionHasNoErrors();

        $profile->refresh();

        $this->assertFalse($profile->enabled);
        $this->assertFalse($profile->is_default);
    }

    private function identity(
        Organization $organization,
        string $hostname,
    ): DnsNameserverIdentity {
        return DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => strtoupper(
                str_replace('.', '-', $hostname),
            ),
            'hostname' => $hostname,
            'ipv4_address' => '192.0.2.53',
            'enabled' => true,
        ]);
    }

    private function userWithRole(
        string $role,
    ): array {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => false,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->organizations()->attach(
            $organization->id,
            [
                'role' => $role,
                'status' => 'active',
                'is_default' => true,
            ],
        );

        return [
            $user,
            $organization,
        ];
    }
}
