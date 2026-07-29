<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\BindZoneRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DnsZoneManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_index_exposes_only_public_identities_from_current_organization(): void
    {
        [
            $admin,
            $organization,
            ,
            ,
            $profile,
            $firstIdentity,
            $secondIdentity,
        ] = $this->context();

        $otherOrganization = Organization::factory()->create();

        $foreignIdentity = DnsNameserverIdentity::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Identidade privada externa',
            'hostname' => 'ns.foreign.example',
            'ipv4_address' => '198.51.100.53',
            'notes' => 'Não deve ser exposta',
            'enabled' => true,
        ]);

        $foreignProfile = DnsNameserverProfile::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Perfil de outra organização',
            'enabled' => true,
        ]);

        $foreignProfile->identities()->attach(
            $foreignIdentity->id,
            ['position' => 1],
        );

        // Simula um pivot inconsistente que não pode vazar pela interface.
        $profile->identities()->attach(
            $foreignIdentity->id,
            ['position' => 3],
        );

        $emptyProfile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Perfil vazio',
            'enabled' => true,
        ]);

        $specialIdentity = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS com caracteres especiais',
            'hostname' => 'ns-<script>\'"&.example',
            'ipv6_address' => '2001:db8::53',
            'notes' => 'Metadado interno',
            'enabled' => true,
        ]);

        $specialProfile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Perfil "especial" <teste>',
            'enabled' => true,
        ]);

        $specialProfile->identities()->attach(
            $specialIdentity->id,
            ['position' => 1],
        );

        $response = $this
            ->actingAs($admin)
            ->get('/zonas')
            ->assertOk();

        $identities = $this->profileIdentities(
            $response,
            $profile->id,
        );

        $this->assertSame([
            [
                'hostname' => $firstIdentity->hostname,
                'ipv4' => $firstIdentity->ipv4_address,
                'ipv6' => null,
            ],
            [
                'hostname' => $secondIdentity->hostname,
                'ipv4' => $secondIdentity->ipv4_address,
                'ipv6' => null,
            ],
        ], $identities);

        $this->assertSame(
            [],
            $this->profileIdentities(
                $response,
                $emptyProfile->id,
            ),
        );

        $this->assertSame(
            [[
                'hostname' => $specialIdentity->normalizedHostname(),
                'ipv4' => null,
                'ipv6' => '2001:db8::53',
            ]],
            $this->profileIdentities(
                $response,
                $specialProfile->id,
            ),
        );

        $response
            ->assertDontSee($foreignProfile->name)
            ->assertDontSee($foreignIdentity->hostname)
            ->assertDontSee('Metadado interno')
            ->assertDontSee($specialIdentity->hostname, false)
            ->assertSee(
                'action="'.route('zones.store').'"',
                false,
            );
    }

    public function test_zone_detail_exposes_profile_json_and_preserves_form_routes(): void
    {
        [
            $admin,
            ,
            $primary,
            $secondary,
            $profile,
        ] = $this->context();

        $zone = $this->createZone(
            $admin,
            $primary,
            $secondary,
            $profile,
        );

        $foreignIdentity = DnsNameserverIdentity::query()->create([
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'Identidade externa no detalhe',
            'hostname' => 'ns.detail-foreign.example',
            'enabled' => true,
        ]);

        $profile->identities()->attach(
            $foreignIdentity->id,
            ['position' => 3],
        );

        $response = $this
            ->actingAs($admin)
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('data-zone-profile-select', false)
            ->assertDontSee($foreignIdentity->hostname)
            ->assertSee(
                'action="'.route('zones.update', $zone).'"',
                false,
            )
            ->assertSee(
                'action="'.route('zones.records.store', $zone).'"',
                false,
            );

        $identities = $this->profileIdentities(
            $response,
            $profile->id,
        );

        $this->assertCount(2, $identities);
        $this->assertSame(
            ['hostname', 'ipv4', 'ipv6'],
            array_keys($identities[0]),
        );
    }

    public function test_admin_can_create_zone(): void
    {
        [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
            $firstIdentity,
            $secondIdentity,
        ] = $this->context();

        $response = $this
            ->actingAs($admin)
            ->post(
                '/zonas',
                $this->payload(
                    $primary,
                    $secondary,
                    $profile,
                ),
            );

        $zone = DnsZone::query()
            ->with([
                'servers',
                'nameserverProfile.identities',
                'records',
            ])
            ->firstOrFail();

        $response->assertRedirect(
            route('zones.show', $zone),
        );

        $this->assertSame(
            'example.com',
            $zone->name,
        );

        $this->assertSame(
            $profile->id,
            $zone->dns_nameserver_profile_id,
        );

        $this->assertSame(
            $firstIdentity->hostname,
            $zone->soa_mname,
        );

        $this->assertCount(
            2,
            $zone->servers,
        );

        $this->assertTrue(
            $zone->records->contains(
                fn ($record): bool => $record->type === 'NS'
                    && $record->name === $zone->name
                    && $record->content
                        === $firstIdentity->hostname,
            ),
        );

        $this->assertTrue(
            $zone->records->contains(
                fn ($record): bool => $record->type === 'NS'
                    && $record->name === $zone->name
                    && $record->content
                        === $secondIdentity->hostname,
            ),
        );

        $this->assertDatabaseCount(
            'dns_zone_versions',
            1,
        );
    }

    public function test_zone_is_isolated_by_organization(): void
    {
        [$admin] = $this->context();

        $other = Organization::query()->create([
            'name' => 'Outra empresa',
            'slug' => 'outra-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => false,
        ]);

        $zone = DnsZone::query()->create([
            'organization_id' => $other->id,
            'name' => 'other.example',
            'kind' => 'primary',
            'serial' => 2026072600,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.other.example',
            'soa_rname' => 'hostmaster.other.example',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('zones.show', $zone))
            ->assertNotFound();
    }

    public function test_record_bumps_serial_and_version(): void
    {
        [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
        ] = $this->context();

        $zone = $this->createZone(
            $admin,
            $primary,
            $secondary,
            $profile,
        );

        $serial = $zone->serial;

        $this->actingAs($admin)
            ->post(
                route(
                    'zones.records.store',
                    $zone,
                ),
                [
                    'name' => 'www',
                    'type' => 'A',
                    'ttl' => 300,
                    'content' => '192.0.2.10',
                ],
            )
            ->assertRedirect();

        $zone->refresh();

        $this->assertGreaterThan(
            $serial,
            $zone->serial,
        );

        $this->assertSame(
            2,
            $zone->version,
        );
    }

    public function test_renderer_generates_bind_zonefile(): void
    {
        [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
        ] = $this->context();

        $zone = $this->createZone(
            $admin,
            $primary,
            $secondary,
            $profile,
        );

        $zone->records()->create([
            'organization_id' => $organization->id,
            'name' => 'www',
            'type' => 'A',
            'ttl' => 300,
            'content' => '192.0.2.20',
            'enabled' => true,
        ]);

        $output = app(BindZoneRenderer::class)
            ->render(
                $zone->fresh([
                    'records',
                    'nameserverProfile.identities',
                ]),
            );

        $this->assertStringContainsString(
            '$ORIGIN example.com.',
            $output,
        );

        $this->assertStringContainsString(
            'www 300 IN A 192.0.2.20',
            $output,
        );

        $this->assertStringContainsString(
            '@ IN NS ns1.provider.example.',
            $output,
        );

        $this->assertStringContainsString(
            '@ IN NS ns2.provider.example.',
            $output,
        );
    }

    public function test_agent_only_receives_linked_published_zone(): void
    {
        [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
        ] = $this->context();

        $zone = $this->createZone(
            $admin,
            $primary,
            $secondary,
            $profile,
        );

        $zone->forceFill([
            'status' => 'published',
        ])->save();

        $token = Str::random(96);

        $agent = DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $primary->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('a', 64),
            'token_hash' => hash('sha256', $token),
            'reported_hostname' => $primary->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'metadata' => [],
        ]);

        $version = DnsZoneVersion::query()
            ->where('dns_zone_id', $zone->id)
            ->where('version', $zone->version)
            ->firstOrFail();

        $version->update([
            'reason' => 'Zona publicada.',
            'snapshot' => app(BindZoneRenderer::class)->snapshot($zone),
        ]);

        DnsAgentPublication::query()->create([
            'organization_id' => $organization->id,
            'dns_zone_version_id' => $version->id,
            'dns_server_id' => $primary->id,
            'dns_agent_id' => $agent->id,
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->getJson('/api/agent/zones')
            ->assertOk()
            ->assertJsonPath(
                'zones.0.name',
                'example.com',
            );

        $this->withToken($token)
            ->get(
                '/api/agent/zones/'
                .$zone->id
                .'/artifact',
            )
            ->assertOk()
            ->assertHeader(
                'X-DNS-Zone-Serial',
                (string) $zone->serial,
            );
    }

    public function test_snapshot_contains_nameserver_profile(): void
    {
        [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
        ] = $this->context();

        $zone = $this->createZone(
            $admin,
            $primary,
            $secondary,
            $profile,
        );

        $version = $zone->versions()
            ->latest('version')
            ->firstOrFail();

        $snapshot = $version->snapshot;

        $this->assertSame(
            $profile->id,
            data_get(
                $snapshot,
                'zone.dns_nameserver_profile_id',
            ),
        );

        $this->assertSame(
            'Produção',
            data_get(
                $snapshot,
                'nameserver_profile.name',
            ),
        );

        $this->assertSame(
            [
                'ns1.provider.example',
                'ns2.provider.example',
            ],
            collect(
                data_get(
                    $snapshot,
                    'nameserver_profile.identities',
                    [],
                ),
            )
                ->pluck('hostname')
                ->all(),
        );
    }

    private function createZone(
        User $admin,
        DnsServer $primary,
        DnsServer $secondary,
        DnsNameserverProfile $profile,
    ): DnsZone {
        $response = $this
            ->actingAs($admin)
            ->post(
                '/zonas',
                $this->payload(
                    $primary,
                    $secondary,
                    $profile,
                ),
            );

        $response->assertSessionHasNoErrors();

        return DnsZone::query()->firstOrFail();
    }

    private function profileIdentities(
        TestResponse $response,
        int $profileId,
    ): array {
        $pattern = sprintf(
            '/<option\\b(?=[^>]*\\bvalue="%d")'
            .'(?=[^>]*\\bdata-profile-identities=\'([^\']*)\')'
            .'[^>]*>/s',
            $profileId,
        );

        $matched = preg_match(
            $pattern,
            $response->getContent(),
            $matches,
        );

        $this->assertSame(
            1,
            $matched,
            "Perfil {$profileId} não encontrado no HTML.",
        );

        $json = html_entity_decode(
            $matches[1],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $decoded = json_decode($json, true);

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            json_last_error_msg(),
        );

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function payload(
        DnsServer $primary,
        DnsServer $secondary,
        DnsNameserverProfile $profile,
    ): array {
        return [
            'name' => 'example.com',
            'kind' => 'primary',
            'dns_nameserver_profile_id' => $profile->id,
            'default_ttl' => 3600,
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'primary_server_id' => $primary->id,
            'secondary_server_id' => $secondary->id,
        ];
    }

    private function context(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $admin->organizations()->attach(
            $organization->id,
            [
                'role' => 'organization_admin',
                'status' => 'active',
                'is_default' => true,
            ],
        );

        $primary = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Publicador primário',
            'hostname' => 'dns01.infra.example',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
        ]);

        $secondary = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Publicador secundário',
            'hostname' => 'dns02.infra.example',
            'role' => 'secondary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
        ]);

        $firstIdentity =
            DnsNameserverIdentity::query()->create([
                'organization_id' => $organization->id,
                'dns_server_id' => $primary->id,
                'name' => 'Nameserver público 01',
                'hostname' => 'ns1.provider.example',
                'ipv4_address' => '192.0.2.10',
                'enabled' => true,
            ]);

        $secondIdentity =
            DnsNameserverIdentity::query()->create([
                'organization_id' => $organization->id,
                'dns_server_id' => $secondary->id,
                'name' => 'Nameserver público 02',
                'hostname' => 'ns2.provider.example',
                'ipv4_address' => '192.0.2.20',
                'enabled' => true,
            ]);

        $profile =
            DnsNameserverProfile::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Produção',
                'is_default' => true,
                'enabled' => true,
            ]);

        $profile->identities()->sync([
            $firstIdentity->id => [
                'position' => 1,
            ],
            $secondIdentity->id => [
                'position' => 2,
            ],
        ]);

        $profile->load('identities');

        return [
            $admin,
            $organization,
            $primary,
            $secondary,
            $profile,
            $firstIdentity,
            $secondIdentity,
        ];
    }
}
