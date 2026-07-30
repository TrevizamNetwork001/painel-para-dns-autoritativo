<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAuthoritativeObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_records_an_idempotent_authoritative_observation(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent', false);

        $server->refresh();
        $this->assertSame(1, $server->authoritative_sequence);
        $this->assertTrue($server->authoritative_runtime['available']);
        $this->assertFalse($server->authoritative_runtime['recursion_enabled']);
        $this->assertDatabaseHas('dns_authoritative_observations', [
            'dns_server_id' => $server->id,
            'dns_zone_id' => $zone->id,
            'expected_serial' => $zone->serial,
            'observed_serial' => $zone->serial,
            'status' => 'synchronized',
            'zone_role' => 'primary',
            'primary_address' => '192.0.2.10',
            'sequence' => 1,
        ]);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('stale', false);

        $this->assertDatabaseCount('dns_authoritative_observation_events', 1);
    }

    public function test_agent_cannot_reuse_event_id_with_another_payload(): void
    {
        [$token, , $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk();

        $payload['server']['recursion_enabled'] = true;

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertConflict()
            ->assertJsonPath('error', 'event_replay');
    }

    public function test_server_calculates_serial_mismatch_from_observed_serial(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);
        $payload['zones'][0]['observed_serial'] = $zone->serial - 1;

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk();

        $this->assertDatabaseHas('dns_authoritative_observations', [
            'dns_server_id' => $server->id,
            'dns_zone_id' => $zone->id,
            'status' => 'serial_mismatch',
        ]);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'dns.serial_mismatch',
            'organization_id' => $zone->organization_id,
        ]);
    }

    public function test_old_event_does_not_regress_newer_state(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $newer = $this->payload($zone);
        $newer['sequence'] = 2;

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $newer)
            ->assertOk();

        $older = $this->payload($zone);
        $older['zones'][0]['observed_serial'] = $zone->serial - 1;

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $older)
            ->assertOk()
            ->assertJsonPath('stale', true);

        $this->assertDatabaseHas('dns_authoritative_observations', [
            'dns_server_id' => $server->id,
            'status' => 'synchronized',
            'sequence' => 2,
        ]);
    }

    public function test_convergence_clears_mismatch_and_audits_only_transitions(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $mismatch = $this->payload($zone);
        $mismatch['zones'][0]['observed_serial'] = $zone->serial - 1;
        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $mismatch)
            ->assertOk();

        $converged = $this->payload($zone);
        $converged['sequence'] = 2;
        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $converged)
            ->assertOk();

        $steady = $this->payload($zone);
        $steady['sequence'] = 3;
        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $steady)
            ->assertOk();

        $this->assertDatabaseHas('dns_authoritative_observations', [
            'dns_server_id' => $server->id,
            'status' => 'synchronized',
            'sequence' => 3,
        ]);
        $this->assertDatabaseCount('security_audits', 2);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'dns.serial_mismatch',
        ]);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'dns.serial_converged',
        ]);
    }

    public function test_primary_offline_and_expired_zone_are_displayed(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);
        $payload['server']['service_active'] = false;
        $payload['server']['tcp_53'] = false;
        $payload['server']['udp_53'] = false;
        $payload['server']['available'] = false;
        $payload['zones'][0]['status'] = 'expired';
        $payload['zones'][0]['observed_serial'] = null;
        $payload['zones'][0]['expires_at'] = now()->subMinute()->toIso8601String();
        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk();

        $admin = $this->organizationUser($zone->organization, 'organization_admin');
        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Primary offline')
            ->assertSee('Zona expirada');
        $this->actingAs($admin)
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('Expirada');
    }

    public function test_error_is_sanitized_and_secret_is_not_rendered(): void
    {
        [$token, $server, $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);
        $payload['zones'][0]['status'] = 'transfer_failed';
        $payload['zones'][0]['observed_serial'] = null;
        $payload['zones'][0]['error'] =
            '<script>alert(1)</script> token=super-secret /etc/bind/private.key';
        $payload['zones'][0]['source'] = 'bind_journal';

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertOk();

        $stored = $server->authoritativeObservations()->sole()->error;
        $this->assertStringNotContainsString('super-secret', $stored);
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('/etc/bind', $stored);

        $admin = $this->organizationUser($zone->organization, 'organization_admin');
        $key = DnsTsigKey::query()->create([
            'organization_id' => $zone->organization_id,
            'name' => 'ui-secret-test',
            'algorithm' => 'hmac-sha256',
            'secret' => base64_encode(random_bytes(32)),
            'enabled' => true,
        ]);
        $zone->forceFill(['dns_tsig_key_id' => $key->id])->save();
        $this->actingAs($admin)
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertDontSee('super-secret')
            ->assertDontSee($key->secret);
    }

    public function test_other_tenant_and_unassigned_zone_receive_not_found(): void
    {
        [$token, , $zone] = $this->authoritativeAgent();
        [, , $foreignZone] = $this->authoritativeAgent();
        $foreignPayload = $this->payload($foreignZone);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $foreignPayload)
            ->assertNotFound();

        $unassigned = $zone->replicate();
        $unassigned->name = 'unassigned-'.$zone->id.'.test';
        $unassigned->save();
        $unassignedPayload = $this->payload($unassigned);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $unassignedPayload)
            ->assertNotFound();
    }

    public function test_viewer_cannot_change_server_state(): void
    {
        [, $server, $zone] = $this->authoritativeAgent();
        $viewer = $this->organizationUser($zone->organization, 'viewer');

        $this->actingAs($viewer)
            ->put(route('servers.update', $server), [
                'name' => 'Alterado',
                'hostname' => $server->hostname,
                'role' => $server->role,
                'environment' => $server->environment,
            ])
            ->assertForbidden();

        $this->assertNotSame('Alterado', $server->fresh()->name);
    }

    public function test_dashboard_uses_real_authoritative_counters(): void
    {
        [$token, , $zone] = $this->authoritativeAgent();
        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $this->payload($zone))
            ->assertOk();
        $admin = $this->organizationUser($zone->organization, 'organization_admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-authoritative-counter="primaries-online"', false)
            ->assertSee('data-authoritative-counter="zonas-sincronizadas"', false)
            ->assertSee('data-authoritative-counter="zonas-divergentes"', false)
            ->assertSee('data-authoritative-value="1"', false)
            ->assertSee('data-authoritative-value="0"', false);
    }

    public function test_api_rejects_excessive_error_payload(): void
    {
        [$token, , $zone] = $this->authoritativeAgent();
        $payload = $this->payload($zone);
        $payload['zones'][0]['error'] = str_repeat('x', 1001);

        $this->withToken($token)
            ->postJson('/api/agent/bind/observations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('zones.0.error');
    }

    private function authoritativeAgent(): array
    {
        $organization = Organization::factory()->create();
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'primary',
            'ipv4_address' => '192.0.2.10',
        ]);
        $zone = DnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => 'observation-'.$organization->id.'.test',
            'kind' => 'primary',
            'serial' => 2026073001,
            'default_ttl' => 3600,
            'soa_mname' => $server->hostname,
            'soa_rname' => 'hostmaster.example.test',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 1,
            'enabled' => true,
        ]);
        $zone->servers()->attach($server->id, ['role' => 'primary']);

        $token = Str::random(96);
        DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', $server->hostname),
            'token_hash' => hash('sha256', $token),
            'registered_at' => now(),
        ]);

        return [$token, $server, $zone];
    }

    private function payload(DnsZone $zone): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'sequence' => 1,
            'observed_at' => now()->toIso8601String(),
            'server' => [
                'service_active' => true,
                'tcp_53' => true,
                'udp_53' => true,
                'recursion_enabled' => false,
                'available' => true,
                'last_reload_at' => null,
                'load_error' => null,
            ],
            'zones' => [[
                'zone_id' => $zone->id,
                'role' => 'primary',
                'observed_serial' => $zone->serial,
                'status' => 'synchronized',
                'zone_state' => 'running',
                'last_refresh_at' => now()->subMinute()->toIso8601String(),
                'next_retry_at' => null,
                'expires_at' => null,
                'primary_address' => '192.0.2.10',
                'transfer_status' => null,
                'last_transfer_at' => null,
                'last_failure_at' => null,
                'error' => null,
                'source' => 'rndc_zonestatus',
            ]],
        ];
    }

    private function organizationUser(
        Organization $organization,
        string $role,
    ): User {
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }
}
