<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollment;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_send_heartbeat(): void
    {
        [$agent, $token, $server] = $this->registeredAgent();

        $this->withToken($token)
            ->postJson('/api/agent/heartbeat', [
                'hostname' => 'NS1.EXEMPLO.NET',
                'agent_version' => '1.2.3',
                'status' => 'online',
                'capabilities' => [
                    'bind' => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($agent->fresh()->last_seen_at);

        $server->refresh();

        $this->assertSame('online', $server->agent_status);
        $this->assertSame('online', $server->status);
        $this->assertSame('1.2.3', $server->agent_version);
        $this->assertTrue($server->capabilities['bind']);
    }

    public function test_agent_can_update_inventory(): void
    {
        [, $token, $server] = $this->registeredAgent();

        $this->withToken($token)
            ->postJson('/api/agent/inventory', [
                'operating_system' => 'Debian',
                'operating_system_version' => '13',
                'bind_version' => '9.20.0',
                'agent_version' => '1.2.3',
                'inventory' => [
                    'cpu_count' => 4,
                    'memory_mb' => 8192,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $server->refresh();

        $this->assertSame('Debian', $server->operating_system);
        $this->assertSame('13', $server->operating_system_version);
        $this->assertSame('9.20.0', $server->bind_version);
        $this->assertSame(4, $server->inventory['cpu_count']);
    }

    public function test_invalid_agent_token_is_rejected(): void
    {
        $this->withToken('token-invalido')
            ->postJson('/api/agent/heartbeat')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_agent_token');
    }

    public function test_admin_can_revoke_agent_and_register_another(): void
    {
        [$agent, $token, $server, $admin] =
            $this->registeredAgent(true);

        $this->actingAs($admin)
            ->post(route('servers.agent.revoke', $server))
            ->assertRedirect(
                route('servers.agent.show', $server)
            );

        $this->assertNotNull($agent->fresh()->revoked_at);

        $this->withToken($token)
            ->postJson('/api/agent/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('error', 'revoked_agent_token');

        $code = 'DNSC-ABCDE-FGHJK-LMNPQ-RSTUV';

        DnsAgentEnrollment::query()->create([
            'organization_id' => $server->organization_id,
            'dns_server_id' => $server->id,
            'created_by' => $admin->id,
            'code_hash' => hash(
                'sha256',
                $this->normalizeCode($code),
            ),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->postJson('/api/agent/enroll', [
            'activation_code' => $code,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('b', 64),
            'hostname' => 'ns1-reinstalado.exemplo.net',
            'agent_version' => '2.0.0',
        ])->assertCreated();

        $this->assertSame(
            2,
            DnsAgent::query()
                ->where('dns_server_id', $server->id)
                ->count(),
        );

        $this->assertSame(
            1,
            DnsAgent::query()
                ->where('dns_server_id', $server->id)
                ->whereNull('revoked_at')
                ->count(),
        );
    }

    private function registeredAgent(
        bool $withAdmin = false,
    ): array {
        $organization = Organization::query()->create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste-'.Str::lower(Str::random(6)),
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

        $server = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS1',
            'hostname' => 'ns1.exemplo.net',
            'role' => 'primary',
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
            'reported_hostname' => 'ns1.exemplo.net',
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'metadata' => [],
        ]);

        return $withAdmin
            ? [$agent, $token, $server, $admin]
            : [$agent, $token, $server];
    }

    private function normalizeCode(string $code): string
    {
        return Str::upper(
            preg_replace('/[^A-Z0-9]/i', '', $code) ?? '',
        );
    }
}
