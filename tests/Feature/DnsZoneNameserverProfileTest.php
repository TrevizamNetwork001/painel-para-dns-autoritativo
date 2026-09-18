<?php

namespace Tests\Feature;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Services\DnsZoneNameserverSynchronizer;
use App\Services\DnsZoneValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DnsZoneNameserverProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_uses_profile_for_soa_and_apex_nameservers(): void
    {
        [$organization, $profile, $first, $second] =
            $this->context();

        $zone = $this->zone($organization);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $zone->refresh();

        $this->assertSame(
            $profile->id,
            $zone->dns_nameserver_profile_id,
        );

        $this->assertSame(
            $first->hostname,
            $zone->soa_mname,
        );

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => $zone->name,
            'type' => 'NS',
            'content' => $first->hostname,
        ]);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => $zone->name,
            'type' => 'NS',
            'content' => $second->hostname,
        ]);
    }

    public function test_imported_fqdn_apex_nameservers_are_replaced_not_duplicated(): void
    {
        [$organization, $profile, $first, $second] = $this->context();

        $zone = $this->zone($organization);

        // Registros como chegam de uma zona importada do BIND: apex em FQDN com ponto
        // final, em outra caixa, ou como "@", mais uma delegação de subdomínio.
        foreach ([
            [$zone->name.'.', 'ns-antigo1.legado.example.'],
            [strtoupper($zone->name).'.', 'ns-antigo2.legado.example.'],
            ['@', 'ns-antigo3.legado.example.'],
            ['filial.'.$zone->name.'.', 'ns.filial.example.'],
        ] as [$name, $content]) {
            $zone->records()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'type' => 'NS',
                'ttl' => 3600,
                'content' => $content,
                'enabled' => true,
            ]);
        }

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $apexNs = $zone->records()
            ->where('type', 'NS')
            ->where('name', $zone->name)
            ->pluck('content')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$first->hostname, $second->hostname])->sort()->values()->all(),
            $apexNs,
        );

        $this->assertDatabaseMissing('dns_records', ['content' => 'ns-antigo1.legado.example.']);
        $this->assertDatabaseMissing('dns_records', ['content' => 'ns-antigo2.legado.example.']);
        $this->assertDatabaseMissing('dns_records', ['content' => 'ns-antigo3.legado.example.']);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => 'filial.'.$zone->name.'.',
            'content' => 'ns.filial.example.',
        ]);
    }

    public function test_in_bailiwick_identity_generates_glue(): void
    {
        [$organization, $profile] = $this->context(
            'example.com',
        );

        $zone = $this->zone($organization);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => 'ns1.example.com',
            'type' => 'A',
            'content' => '192.0.2.10',
        ]);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => 'ns2.example.com',
            'type' => 'AAAA',
            'content' => '2001:db8::20',
        ]);
    }

    public function test_external_nameservers_do_not_generate_glue(): void
    {
        [$organization, $profile] = $this->context(
            'provider.example',
        );

        $zone = $this->zone($organization);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $this->assertDatabaseMissing('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'A',
            'name' => 'ns1.provider.example',
        ]);

        $this->assertDatabaseMissing('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'AAAA',
            'name' => 'ns2.provider.example',
        ]);
    }

    public function test_profile_from_another_organization_is_rejected(): void
    {
        [$organization] = $this->context();

        $otherOrganization = Organization::query()->create([
            'name' => 'Outra empresa',
            'slug' => 'outra-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'is_default' => false,
        ]);

        $profile = DnsNameserverProfile::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Outro perfil',
            'is_default' => true,
            'enabled' => true,
        ]);

        $this->expectException(
            ValidationException::class,
        );

        app(DnsZoneNameserverSynchronizer::class)
            ->profileForOrganization(
                $profile->id,
                $organization->id,
            );
    }

    public function test_validator_accepts_synchronized_profile(): void
    {
        [$organization, $profile] = $this->context();

        $zone = $this->zone($organization);
        $server = $this->publicationServer(
            $organization,
        );

        $zone->servers()->sync([
            $server->id => [
                'role' => 'primary',
            ],
        ]);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $result = app(DnsZoneValidator::class)
            ->validate($zone->fresh());

        $this->assertTrue(
            $result['ok'],
            implode("\n", $result['errors']),
        );
    }

    public function test_validator_flags_server_not_reading_managed_include(): void
    {
        [$organization, $profile] = $this->context();

        $zone = $this->zone($organization);
        $server = $this->publicationServer(
            $organization,
        );
        $server->forceFill([
            'bind_readiness' => [
                'include_wired' => [
                    'expected_include' => '/etc/bind/dns-center-managed.conf',
                    'statement_found' => false,
                ],
            ],
        ])->save();

        $zone->servers()->sync([
            $server->id => [
                'role' => 'primary',
            ],
        ]);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $result = app(DnsZoneValidator::class)
            ->validate($zone->fresh());

        $this->assertFalse($result['ok']);

        $this->assertContains(
            'O servidor Servidor de publicação não está lendo o include gerenciado do DNS Center — esta zona continuará presa depois de publicar.',
            $result['errors'],
        );
    }

    public function test_validator_flags_legacy_zone_block_conflicting_with_managed_zone(): void
    {
        [$organization, $profile] = $this->context();

        $zone = $this->zone($organization);
        $server = $this->publicationServer(
            $organization,
        );
        $server->forceFill([
            'bind_readiness' => [
                'legacy_zone_blocks' => [
                    'managed_include' => '/etc/bind/dns-center-managed.conf',
                    'blocks' => [
                        [
                            'name' => 'example.com',
                            'source_file' => '/etc/bind/named.conf.local',
                            'start_line' => 2,
                            'end_line' => 6,
                            'declared_type' => 'master',
                            'hash' => str_repeat('a', 64),
                            'snippet' => null,
                        ],
                    ],
                ],
            ],
        ])->save();

        $zone->servers()->sync([
            $server->id => [
                'role' => 'primary',
            ],
        ]);

        app(DnsZoneNameserverSynchronizer::class)
            ->synchronize($zone, $profile);

        $result = app(DnsZoneValidator::class)
            ->validate($zone->fresh());

        $this->assertFalse($result['ok']);

        $this->assertContains(
            'O servidor Servidor de publicação já tem "example.com" declarada fora do include gerenciado, em /etc/bind/named.conf.local:2 — remova o bloco antigo antes de publicar, ou o apply será recusado pelo named-checkconf.',
            $result['errors'],
        );
    }

    private function context(
        string $identityDomain = 'provider.example',
    ): array {
        $organization = Organization::query()->create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $first = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS 1',
            'hostname' => 'ns1.'.$identityDomain,
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        $second = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS 2',
            'hostname' => 'ns2.'.$identityDomain,
            'ipv6_address' => '2001:db8::20',
            'enabled' => true,
        ]);

        $profile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Produção',
            'is_default' => true,
            'enabled' => true,
        ]);

        $profile->identities()->sync([
            $first->id => ['position' => 1],
            $second->id => ['position' => 2],
        ]);

        $profile->load('identities');

        return [
            $organization,
            $profile,
            $first,
            $second,
        ];
    }

    private function zone(
        Organization $organization,
    ): DnsZone {
        return DnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => 'example.com',
            'kind' => 'primary',
            'serial' => 2026072800,
            'default_ttl' => 3600,
            'soa_mname' => 'legacy.example',
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
        ]);
    }

    private function publicationServer(
        Organization $organization,
    ): DnsServer {
        return DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Servidor de publicação',
            'hostname' => 'dns01.infra.example',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
        ]);
    }
}
