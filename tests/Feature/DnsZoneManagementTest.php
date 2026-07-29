<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use App\Services\BindZoneRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsZoneManagementTest extends TestCase
{
    use RefreshDatabase;

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
                fn ($record): bool =>
                    $record->type === 'NS'
                    && $record->name === $zone->name
                    && $record->content
                        === $firstIdentity->hostname,
            ),
        );

        $this->assertTrue(
            $zone->records->contains(
                fn ($record): bool =>
                    $record->type === 'NS'
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
            'slug' =>
                'outra-'.Str::lower(Str::random(6)),
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

        DnsAgent::query()->create([
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
            'slug' =>
                'empresa-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = User::factory()->create([
            'current_organization_id' =>
                $organization->id,
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
