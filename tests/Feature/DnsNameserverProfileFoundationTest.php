<?php

namespace Tests\Feature;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsNameserverProfileFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_can_be_linked_to_physical_server(): void
    {
        $organization = Organization::factory()->create();

        $server = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Servidor DNS 01',
            'hostname' => 'dns01.infra.example',
            'ipv4_address' => '192.0.2.10',
            'ipv6_address' => '2001:db8::10',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
        ]);

        $identity = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'name' => 'NS compartilhado 01',
            'hostname' => 'ns1.provider.example',
            'ipv4_address' => '192.0.2.10',
            'ipv6_address' => '2001:db8::10',
            'enabled' => true,
        ]);

        $this->assertTrue(
            $identity->server->is($server),
        );

        $this->assertTrue(
            $server->nameserverIdentities
                ->contains($identity),
        );

        $this->assertSame(
            'ns1.provider.example',
            $identity->normalizedHostname(),
        );

        $this->assertTrue(
            $identity->hasAddress(),
        );
    }

    public function test_external_identity_does_not_require_physical_server(): void
    {
        $organization = Organization::factory()->create();

        $identity = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => null,
            'name' => 'DNS externo',
            'hostname' => 'ns1.external.example',
            'ipv4_address' => '198.51.100.20',
            'enabled' => true,
        ]);

        $this->assertNull(
            $identity->server,
        );

        $this->assertTrue(
            $identity->hasAddress(),
        );
    }

    public function test_profile_keeps_nameserver_order(): void
    {
        $organization = Organization::factory()->create();

        $first = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS 01',
            'hostname' => 'ns1.provider.example',
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        $second = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS 02',
            'hostname' => 'ns2.provider.example',
            'ipv4_address' => '192.0.2.11',
            'enabled' => true,
        ]);

        $profile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Nameservers compartilhados',
            'is_default' => true,
            'enabled' => true,
        ]);

        $profile->identities()->attach([
            $first->id => [
                'position' => 1,
            ],
            $second->id => [
                'position' => 2,
            ],
        ]);

        $profile->load('identities');

        $this->assertSame(
            [
                'ns1.provider.example',
                'ns2.provider.example',
            ],
            $profile->identities
                ->pluck('hostname')
                ->all(),
        );

        $this->assertSame(
            [1, 2],
            $profile->identities
                ->pluck('pivot.position')
                ->all(),
        );
    }

    public function test_identities_and_profiles_are_isolated_by_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        DnsNameserverIdentity::query()->create([
            'organization_id' => $organizationA->id,
            'name' => 'NS Empresa A',
            'hostname' => 'ns1.company-a.example',
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        DnsNameserverIdentity::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'NS Empresa B',
            'hostname' => 'ns1.company-b.example',
            'ipv4_address' => '198.51.100.10',
            'enabled' => true,
        ]);

        DnsNameserverProfile::query()->create([
            'organization_id' => $organizationA->id,
            'name' => 'Perfil A',
            'enabled' => true,
        ]);

        DnsNameserverProfile::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'Perfil B',
            'enabled' => true,
        ]);

        $this->assertSame(
            1,
            DnsNameserverIdentity::query()
                ->forOrganization($organizationA->id)
                ->count(),
        );

        $this->assertSame(
            1,
            DnsNameserverProfile::query()
                ->forOrganization($organizationA->id)
                ->count(),
        );
    }

    public function test_same_hostname_can_exist_in_different_organizations(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        DnsNameserverIdentity::query()->create([
            'organization_id' => $organizationA->id,
            'name' => 'NS Empresa A',
            'hostname' => 'ns1.shared.example',
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        DnsNameserverIdentity::query()->create([
            'organization_id' => $organizationB->id,
            'name' => 'NS Empresa B',
            'hostname' => 'ns1.shared.example',
            'ipv4_address' => '198.51.100.10',
            'enabled' => true,
        ]);

        $this->assertSame(
            2,
            DnsNameserverIdentity::query()
                ->where(
                    'hostname',
                    'ns1.shared.example',
                )
                ->count(),
        );
    }
}
