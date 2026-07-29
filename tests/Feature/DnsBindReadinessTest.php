<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_agent_reports_factual_bind_absence_idempotently(): void
    {
        $context = $this->context();
        $payload = $this->readinessPayload(bindInstalled: false);

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/readiness', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', false);

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/readiness', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $server = $context['server']->fresh();
        $this->assertFalse($server->bind_readiness['bind_installed']);
        $this->assertNull($server->bind_version);
        $this->assertFalse($server->bind_readiness['service']['active']);
        $this->assertFalse($server->bind_readiness['listeners']['tcp_53']);
        $this->assertNotNull($server->bind_readiness_at);
    }

    public function test_readiness_rejects_arbitrary_paths_and_replayed_payload(): void
    {
        $context = $this->context();
        $payload = $this->readinessPayload();

        $payload['paths']['named_conf'] = '/tmp/attacker.conf';

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/readiness', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'path_not_allowed');

        $payload = $this->readinessPayload();

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/readiness', $payload)
            ->assertOk();

        $payload['bind_version'] = 'alterada';

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/readiness', $payload)
            ->assertConflict()
            ->assertJsonPath('error', 'event_replay');
    }

    public function test_agent_cannot_change_another_server_readiness_or_operation(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra empresa');

        $this->withToken($foreign['token'])
            ->postJson('/api/agent/bind/readiness', $this->readinessPayload())
            ->assertOk();

        $this->assertNull($context['server']->fresh()->bind_readiness);

        $operation = $this->authorizedOperation($context);

        $this->withToken($foreign['token'])
            ->postJson('/api/agent/bind/operations/'.$operation->id.'/report', [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ])
            ->assertNotFound()
            ->assertJsonPath('error', 'operation_not_available');
    }

    public function test_install_plan_requires_admin_authorization_and_is_idempotent(): void
    {
        $context = $this->context();
        $this->storeReadiness($context, false);

        foreach (['viewer', 'operator'] as $role) {
            $member = $this->member($context['organization'], $role);

            $this->actingAs($member)
                ->post(route('servers.bind.plan', $context['server']))
                ->assertForbidden();
        }

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.plan', $context['server']))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Plano BIND preparado. Nenhuma ação foi executada.',
            );

        $operation = DnsBindOperation::query()->firstOrFail();
        $this->assertSame('planned', $operation->status);
        $this->assertSame('install_bind', $operation->action);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.plan', $context['server']))
            ->assertRedirect();
        $this->assertDatabaseCount('dns_bind_operations', 1);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.authorize', [$context['server'], $operation]), [
                'confirmation' => 'AUTORIZAR BIND '.$context['server']->name,
            ])->assertRedirect();

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.authorize', [$context['server'], $operation]), [
                'confirmation' => 'AUTORIZAR BIND '.$context['server']->name,
            ])->assertRedirect();

        $this->assertSame('authorized', $operation->fresh()->status);
        $this->assertDatabaseCount('dns_bind_operations', 1);
        $this->assertDatabaseCount('security_audits', 1);
    }

    public function test_agent_receives_only_authorized_allowlisted_action_and_reports_result(): void
    {
        $context = $this->context();

        $this->withToken($context['token'])
            ->getJson('/api/agent/bind/operations/next')
            ->assertOk()
            ->assertJsonPath('operation', null);

        $operation = $this->authorizedOperation($context);

        $response = $this->withToken($context['token'])
            ->getJson('/api/agent/bind/operations/next')
            ->assertOk()
            ->assertJsonPath('operation.action', 'install_bind')
            ->assertJsonMissing(['command'])
            ->json('operation');

        $eventId = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'authorization_nonce' => $response['authorization_nonce'],
            'status' => 'running',
        ];

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/operations/'.$operation->id.'/report', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', false);

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/operations/'.$operation->id.'/report', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->withToken($context['token'])
            ->postJson('/api/agent/bind/operations/'.$operation->id.'/report', [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $response['authorization_nonce'],
                'status' => 'failed',
                'result' => ['rolled_back' => true, 'secret' => 'não persistir'],
                'error' => '<b>falha</b> token=segredo /etc/private/key',
            ])->assertOk()
            ->assertJsonPath('status', 'failed');

        $failed = $operation->fresh();
        $this->assertTrue($failed->result['rolled_back']);
        $this->assertArrayNotHasKey('secret', $failed->result);
        $this->assertStringNotContainsString('<b>', $failed->error);
        $this->assertStringNotContainsString('segredo', $failed->error);
        $this->assertStringNotContainsString('/etc/private', $failed->error);
    }

    public function test_server_page_shows_factual_readiness_without_health_claim(): void
    {
        $context = $this->context();
        $this->storeReadiness($context, true);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Prontidão factual')
            ->assertSee('Listener TCP 53')
            ->assertSee('Não detectado')
            ->assertDontSee('Saudável');
    }

    private function authorizedOperation(array $context): DnsBindOperation
    {
        return DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'install_bind',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);
    }

    private function storeReadiness(array $context, bool $installed): void
    {
        $this->withToken($context['token'])
            ->postJson(
                '/api/agent/bind/readiness',
                $this->readinessPayload($installed),
            )
            ->assertOk();
    }

    private function readinessPayload(bool $bindInstalled = true): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'detected_at' => now()->toIso8601String(),
            'os_family' => 'debian',
            'bind_installed' => $bindInstalled,
            'bind_version' => $bindInstalled ? 'BIND 9.20.0' : null,
            'paths' => [
                'named_conf' => $bindInstalled ? '/etc/bind/named.conf' : null,
                'include_dir' => $bindInstalled ? '/etc/bind' : null,
                'zones_dir' => '/etc/bind/dns-center-zones',
                'named_checkconf' => $bindInstalled ? '/usr/bin/named-checkconf' : null,
                'named_checkzone' => $bindInstalled ? '/usr/bin/named-checkzone' : null,
                'rndc' => $bindInstalled ? '/usr/sbin/rndc' : null,
            ],
            'service' => [
                'name' => 'bind9',
                'active' => false,
                'user' => $bindInstalled ? 'bind' : null,
                'group' => $bindInstalled ? 'bind' : null,
            ],
            'listeners' => ['tcp_53' => false, 'udp_53' => false],
            'network' => ['ipv4' => true, 'ipv6' => false],
            'security' => ['apparmor' => 'enforcing', 'selinux' => 'absent'],
            'permissions' => [
                'can_manage_include' => false,
                'can_manage_zones' => false,
            ],
        ];
    }

    private function context(string $name = 'Empresa Teste'): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);
        $admin = $this->member($organization, 'organization_admin');
        $server = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS '.$organization->id,
            'hostname' => 'ns'.$organization->id.'.example.test',
            'ipv4_address' => '192.0.2.10',
            'role' => 'standalone',
            'environment' => 'production',
            'status' => 'pending',
            'enabled' => true,
            'agent_status' => 'not_installed',
        ]);
        $token = Str::random(96);
        $agent = DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('a', 64),
            'token_hash' => hash('sha256', $token),
            'registered_at' => now(),
        ]);

        return compact('organization', 'admin', 'server', 'token', 'agent');
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
}
