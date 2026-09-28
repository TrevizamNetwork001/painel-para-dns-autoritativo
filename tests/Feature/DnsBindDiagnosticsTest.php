<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindDiagnosticsTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $lastSecondaryToken = null;

    // --- Log do BIND -----------------------------------------------------

    public function test_admin_can_request_bind_log_and_viewer_cannot(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'authorized');

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'tail_bind_log',
            'status' => 'authorized',
        ]);

        $viewer = $this->member($context['organization'], 'viewer');
        $this->actingAs($viewer)
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertForbidden();
    }

    public function test_bind_log_request_is_rejected_while_one_is_already_in_flight(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertOk();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertStatus(409);

        $this->assertDatabaseCount('dns_bind_operations', 1);
    }

    public function test_bind_log_next_operation_endpoint_hands_params_to_the_agent(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertOk();

        $this->withToken($context['token'])
            ->getJson('/api/agent/bind/operations/next')
            ->assertOk()
            ->assertJsonPath('operation.action', 'tail_bind_log')
            ->assertJsonPath('operation.params.lines', 200);
    }

    public function test_bind_log_status_reports_sanitized_lines_from_the_agent(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertOk();

        $operation = DnsBindOperation::query()->firstOrFail();

        $this->reportOperation($context, $operation, 'running');
        $this->reportOperation($context, $operation, 'succeeded', [
            'unit' => 'named',
            'lines' => [
                '2026-09-28T03:15:00+0000 host named[1]: zone example.com/IN: loaded serial 1',
                'token: nao-deve-vazar-isto',
            ],
            'truncated' => false,
            'requested_lines' => 200,
        ]);

        $response = $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.log.status', [$context['server'], $operation]))
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.unit', 'named')
            ->assertJsonCount(2, 'result.lines');

        $body = $response->json();
        $this->assertStringContainsString('loaded serial 1', $body['result']['lines'][0]);
        $this->assertStringNotContainsString('nao-deve-vazar-isto', $body['result']['lines'][1]);
        $this->assertStringContainsString('[removido]', $body['result']['lines'][1]);
    }

    public function test_bind_log_status_is_hidden_for_another_tenant(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra Organização');

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.log.store', $context['server']))
            ->assertOk();

        $operation = DnsBindOperation::query()->firstOrFail();

        $this->actingAs($foreign['admin'])
            ->getJson(route('servers.bind.log.status', [$context['server'], $operation]))
            ->assertNotFound();
    }

    // --- Forçar transferência --------------------------------------------

    public function test_admin_can_force_transfer_on_the_secondary_server(): void
    {
        $context = $this->context();
        $secondary = $this->secondaryServer($context);
        $zone = $this->zoneWithServers($context, $secondary);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.transfer.store', [$secondary, $zone]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $secondary->id,
            'action' => 'force_zone_transfer',
            'status' => 'authorized',
        ]);

        $operation = DnsBindOperation::query()->where('action', 'force_zone_transfer')->firstOrFail();
        $this->assertSame($zone->name, $operation->params['zone_name']);
    }

    public function test_force_transfer_is_rejected_on_the_primary_server(): void
    {
        $context = $this->context();
        $secondary = $this->secondaryServer($context);
        $zone = $this->zoneWithServers($context, $secondary);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.transfer.store', [$context['server'], $zone]))
            ->assertStatus(409);

        $this->assertDatabaseMissing('dns_bind_operations', ['action' => 'force_zone_transfer']);
    }

    public function test_force_transfer_is_rejected_when_server_is_unrelated_to_the_zone(): void
    {
        $context = $this->context();
        $unrelated = $this->secondaryServer($context, 'sem-relacao');
        $secondary = $this->secondaryServer($context, 'secundario-de-verdade');
        $zone = $this->zoneWithServers($context, $secondary);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.transfer.store', [$unrelated, $zone]))
            ->assertStatus(409);
    }

    public function test_viewer_cannot_force_transfer(): void
    {
        $context = $this->context();
        $secondary = $this->secondaryServer($context);
        $zone = $this->zoneWithServers($context, $secondary);
        $viewer = $this->member($context['organization'], 'viewer');

        $this->actingAs($viewer)
            ->postJson(route('servers.bind.transfer.store', [$secondary, $zone]))
            ->assertForbidden();
    }

    public function test_force_transfer_next_operation_endpoint_hands_zone_name_to_the_agent(): void
    {
        $context = $this->context();
        $secondary = $this->secondaryServer($context);
        $zone = $this->zoneWithServers($context, $secondary);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.transfer.store', [$secondary, $zone]))
            ->assertOk();

        $this->withToken($this->lastSecondaryToken)
            ->getJson('/api/agent/bind/operations/next')
            ->assertOk()
            ->assertJsonPath('operation.action', 'force_zone_transfer')
            ->assertJsonPath('operation.params.zone_name', $zone->name);
    }

    public function test_force_transfer_status_reports_serial_before_and_after(): void
    {
        $context = $this->context();
        $secondary = $this->secondaryServer($context);
        $zone = $this->zoneWithServers($context, $secondary);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.transfer.store', [$secondary, $zone]))
            ->assertOk();

        $operation = DnsBindOperation::query()->where('action', 'force_zone_transfer')->firstOrFail();

        $this->withToken($this->lastSecondaryToken)->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ],
        )->assertOk();

        $this->withToken($this->lastSecondaryToken)->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'succeeded',
                'result' => [
                    'requested' => true,
                    'zone_name' => $zone->name,
                    'serial_before' => 1,
                    'serial_after' => 2,
                    'zone_state' => 'running',
                ],
            ],
        )->assertOk();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.transfer.status', [$secondary, $operation]))
            ->assertOk()
            ->assertJsonPath('result.serial_before', 1)
            ->assertJsonPath('result.serial_after', 2);
    }

    private function reportOperation(array $context, DnsBindOperation $operation, string $status, ?array $result = null): void
    {
        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            array_filter([
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => $status,
                'result' => $result,
            ], fn ($value) => $value !== null),
        )->assertOk();
    }

    private function secondaryServer(array $context, string $name = 'secundario'): DnsServer
    {
        $server = DnsServer::factory()->create([
            'organization_id' => $context['organization']->id,
            'name' => $name,
            'hostname' => $name.'.'.Str::slug($context['organization']->name).'.example',
            'role' => 'secondary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        $token = Str::random(96);
        DnsAgent::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', $token),
            'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);

        $this->lastSecondaryToken = $token;

        return $server;
    }

    private function zoneWithServers(array $context, DnsServer $secondary): DnsZone
    {
        $zone = DnsZone::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'example.com',
            'kind' => 'primary',
            'serial' => 1,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.example.com',
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 1,
            'enabled' => true,
        ]);

        $zone->servers()->sync([
            $context['server']->id => ['role' => 'primary'],
            $secondary->id => ['role' => 'secondary'],
        ]);

        return $zone;
    }

    private function member(Organization $organization, string $role): User
    {
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

    private function context(string $name = 'Empresa Teste'): array
    {
        $organization = Organization::factory()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = $this->member($organization, 'organization_admin');

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name.' ns1',
            'hostname' => 'ns1.'.Str::slug($name).'.example',
            'role' => 'primary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        $token = Str::random(96);
        $agent = DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', $token),
            'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);

        return compact('organization', 'admin', 'server', 'agent', 'token');
    }
}
