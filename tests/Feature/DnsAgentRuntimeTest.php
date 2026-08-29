<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
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

    public function test_admin_can_revoke_agent(): void
    {
        [$agent, $token, $server, $admin] =
            $this->registeredAgent(true);

        $this->actingAs($admin)
            ->post(route('servers.agent.revoke', $server))
            ->assertRedirect(
                route('servers.agent.show', $server)
            );

        $this->assertNotNull($agent->fresh()->revoked_at);

        $this->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Credencial revogada — novo vínculo necessário')
            ->assertSee('Gerar vínculo')
            ->assertSee('Enrollment pré-vinculado')
            ->assertDontSee('Máquina sem agente')
            ->assertDontSee('wget -qO-', false);

        $this->withToken($token)
            ->postJson('/api/agent/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('error', 'revoked_agent_token');

        $this->assertSame(
            0,
            DnsAgent::query()
                ->where('dns_server_id', $server->id)
                ->whereNull('revoked_at')
                ->count(),
        );
    }

    public function test_agent_page_uses_the_shared_theme_preference(): void
    {
        [, , $server, $admin] = $this->registeredAgent(true);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee(
                "localStorage.getItem('dns-center-theme')",
                false,
            )
            ->assertSee(
                'document.documentElement.dataset.theme',
                false,
            );
    }

    public function test_agent_success_message_uses_auto_dismiss_toast(): void
    {
        [, , $server, $admin] = $this->registeredAgent(true);

        $this->actingAs($admin)
            ->withSession([
                'status' => 'Instalação do agente aprovada.',
            ])
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Instalação do agente aprovada.')
            ->assertSee('data-flash-toast', false)
            ->assertSee('data-flash-timeout="6000"', false)
            ->assertSee('data-flash-toast-close', false);
    }

    public function test_online_agent_page_shows_operational_summary_and_collapsed_details(): void
    {
        [$agent, , $server, $admin] = $this->registeredAgent(true);

        $agent->update(['last_seen_at' => now()]);
        $server->update([
            'agent_status' => 'online',
            'status' => 'online',
            'agent_version' => '0.6.0',
            'bind_version' => 'BIND 9.20.26',
            'bind_readiness_at' => now(),
            'bind_readiness' => [
                'bind_installed' => true,
                'bind_version' => 'BIND 9.20.26',
                'service' => ['active' => true],
                'listeners' => ['tcp_53' => true, 'udp_53' => true],
                'paths' => [
                    'named_conf' => '/etc/bind/named.conf',
                    'include_dir' => '/etc/bind',
                    'zones_dir' => '/etc/bind/dns-center-zones',
                    'named_checkconf' => '/usr/bin/named-checkconf',
                    'named_checkzone' => '/usr/bin/named-checkzone',
                    'rndc' => '/usr/sbin/rndc',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Resumo operacional')
            ->assertSee('Gerenciamento atual: Externo / CLI')
            ->assertSee('Nenhuma configuração ativa do BIND é modificada.')
            ->assertSee('Descoberto')
            ->assertSee('Importado')
            ->assertSee('Gerenciado')
            ->assertSee('Nenhuma publicação destinada a este servidor.')
            ->assertSee('Detalhes técnicos')
            ->assertSee($agent->agent_uuid)
            ->assertSee('/etc/bind/named.conf')
            ->assertSee('Zona de risco')
            ->assertSee('Revogar credencial')
            ->assertSee('Software do agente')
            ->assertDontSee('Gerar vínculo');
    }

    public function test_offline_agent_page_keeps_last_known_inventory_and_contact(): void
    {
        [$agent, , $server, $admin] = $this->registeredAgent(true);
        $lastContact = now()->subMinutes(8);
        $agent->update(['last_seen_at' => $lastContact]);
        $server->update([
            'agent_status' => 'offline',
            'status' => 'offline',
            'bind_version' => 'BIND 9.20.0',
        ]);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Agente offline')
            ->assertSee('última informação conhecida')
            ->assertSee($lastContact->format('d/m/Y H:i:s'))
            ->assertSee('BIND 9.20.0')
            ->assertSee('Ver diagnóstico');
    }

    public function test_missing_readiness_warning_is_visible_at_the_top(): void
    {
        [$agent, , $server, $admin] = $this->registeredAgent(true);
        $agent->update(['last_seen_at' => now()]);
        $server->update(['agent_status' => 'online']);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Atenção operacional')
            ->assertSee('O inventário de prontidão ainda não foi recebido.')
            ->assertSee('Readiness: WARNING');
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
}
