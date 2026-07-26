<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
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
        [$admin, $organization, $primary, $secondary] = $this->context();

        $response = $this->actingAs($admin)->post('/zonas', $this->payload($primary, $secondary));
        $zone = DnsZone::query()->firstOrFail();

        $response->assertRedirect(route('zones.show', $zone));
        $this->assertSame('example.com', $zone->name);
        $this->assertCount(2, $zone->servers);
        $this->assertDatabaseCount('dns_zone_versions', 1);
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

        $this->actingAs($admin)->get(route('zones.show', $zone))->assertNotFound();
    }

    public function test_record_bumps_serial_and_version(): void
    {
        [$admin, $organization, $primary, $secondary] = $this->context();
        $zone = $this->createZone($admin, $primary, $secondary);
        $serial = $zone->serial;

        $this->actingAs($admin)->post(route('zones.records.store', $zone), [
            'name' => 'www',
            'type' => 'A',
            'ttl' => 300,
            'content' => '192.0.2.10',
        ])->assertRedirect();

        $zone->refresh();
        $this->assertGreaterThan($serial, $zone->serial);
        $this->assertSame(2, $zone->version);
    }

    public function test_renderer_generates_bind_zonefile(): void
    {
        [$admin, $organization, $primary, $secondary] = $this->context();
        $zone = $this->createZone($admin, $primary, $secondary);

        $zone->records()->create([
            'organization_id' => $organization->id,
            'name' => 'www',
            'type' => 'A',
            'ttl' => 300,
            'content' => '192.0.2.20',
            'enabled' => true,
        ]);

        $output = app(BindZoneRenderer::class)->render($zone->fresh('records'));

        $this->assertStringContainsString('$ORIGIN example.com.', $output);
        $this->assertStringContainsString('www 300 IN A 192.0.2.20', $output);
    }

    public function test_agent_only_receives_linked_published_zone(): void
    {
        [$admin, $organization, $primary, $secondary] = $this->context();
        $zone = $this->createZone($admin, $primary, $secondary);

        $zone->records()->createMany([
            [
                'organization_id' => $organization->id,
                'name' => $zone->name,
                'type' => 'NS',
                'content' => 'ns1.example.com',
                'enabled' => true,
            ],
            [
                'organization_id' => $organization->id,
                'name' => $zone->name,
                'type' => 'NS',
                'content' => 'ns2.example.com',
                'enabled' => true,
            ],
        ]);

        $zone->forceFill(['status' => 'published'])->save();
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
            ->assertJsonPath('zones.0.name', 'example.com');

        $this->withToken($token)
            ->get('/api/agent/zones/'.$zone->id.'/artifact')
            ->assertOk()
            ->assertHeader('X-DNS-Zone-Serial', (string) $zone->serial);
    }

    private function createZone(
        User $admin,
        DnsServer $primary,
        DnsServer $secondary,
    ): DnsZone {
        $this->actingAs($admin)->post('/zonas', $this->payload($primary, $secondary));

        return DnsZone::query()->firstOrFail();
    }

    private function payload(DnsServer $primary, DnsServer $secondary): array
    {
        return [
            'name' => 'example.com',
            'kind' => 'primary',
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.example.com',
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

        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $primary = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS1',
            'hostname' => 'ns1.example.com',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
        ]);

        $secondary = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS2',
            'hostname' => 'ns2.example.com',
            'role' => 'secondary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
        ]);

        return [$admin, $organization, $primary, $secondary];
    }
}
