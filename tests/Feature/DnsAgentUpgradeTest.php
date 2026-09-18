<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use App\Support\AgentArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_upgrade_and_it_is_admin_only(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertRedirect(route('servers.agent.show', $context['server']));

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('security_audits', ['event' => 'agent.upgrade_requested']);

        $viewer = $this->member($context['organization'], 'viewer');
        $this->actingAs($viewer)
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertForbidden();
    }

    public function test_upgrade_requires_two_factor(): void
    {
        config()->set('security.admin_2fa.required', true);
        $context = $this->context();
        $adminWithoutTwoFactor = User::factory()->create([
            'current_organization_id' => $context['organization']->id,
            'status' => 'active',
            'must_change_password' => false,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $adminWithoutTwoFactor->organizations()->attach($context['organization']->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);

        $this->actingAs($adminWithoutTwoFactor)
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertRedirect(route('security.two-factor.setup'));

        $this->assertDatabaseCount('dns_bind_operations', 0);
    }

    public function test_other_tenant_cannot_request_upgrade(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra Organização');

        $this->actingAs($foreign['admin'])
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertNotFound();
    }

    public function test_upgrade_requires_active_agent(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Sem Agente', 'slug' => 'sem-agente-'.Str::lower(Str::random(6)),
            'status' => 'active', 'is_default' => true,
        ]);
        $admin = $this->member($organization, 'organization_admin');
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->post(route('servers.agent.upgrade', $server))
            ->assertStatus(409);
    }

    public function test_in_flight_upgrade_blocks_duplicate_request(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])->post(route('servers.agent.upgrade', $context['server']))->assertRedirect();
        $this->actingAs($context['admin'])
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertStatus(409);

        $this->assertDatabaseCount('dns_bind_operations', 1);
    }

    public function test_offline_agent_is_rejected_immediately(): void
    {
        $context = $this->context();
        $context['server']->update(['agent_status' => 'offline']);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.agent.upgrade', $context['server']))
            ->assertStatus(409)
            ->assertJsonPath('message', 'O agente está offline. Restabeleça a comunicação antes de solicitar a atualização.');

        $this->assertDatabaseCount('dns_bind_operations', 0);
    }

    public function test_uncollected_upgrade_expires_and_allows_a_new_request(): void
    {
        config()->set('security.agent_upgrade.ttl_minutes', 10);
        $context = $this->context();
        $expired = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinutes(11),
        ]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('error', 'A solicitação expirou porque o agente não a coletou dentro do prazo.');

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNotNull($expired->fresh()->completed_at);

        $this->actingAs($context['admin'])
            ->post(route('servers.agent.upgrade', $context['server']))
            ->assertRedirect();

        $this->assertDatabaseCount('dns_bind_operations', 2);
    }

    public function test_agent_cannot_collect_an_expired_upgrade(): void
    {
        config()->set('security.agent_upgrade.ttl_minutes', 10);
        $context = $this->context();
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinutes(11),
        ]);

        $this->withToken($context['token'])
            ->getJson(route('api.agent.bind.operations.next'))
            ->assertOk()
            ->assertJsonPath('operation', null);

        $this->assertDatabaseHas('dns_bind_operations', ['status' => 'expired']);
    }

    public function test_running_upgrade_has_its_own_execution_deadline(): void
    {
        config()->set('security.agent_upgrade.ttl_minutes', 10);
        config()->set('security.agent_upgrade.running_ttl_minutes', 30);
        $context = $this->context();
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'running',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinutes(16),
            'started_at' => now()->subMinutes(12),
        ]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'running');

        $operation->update(['started_at' => now()->subMinutes(31)]);
        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'expired');
    }

    public function test_status_endpoint_reflects_operation_lifecycle(): void
    {
        $context = $this->context();
        $this->actingAs($context['admin'])->post(route('servers.agent.upgrade', $context['server']));

        $response = $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']));

        $response->assertOk()->assertJsonPath('status', 'authorized');
    }

    public function test_agent_page_renders_compact_async_upgrade_states_without_fake_percentage(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Atualização do agente')
            ->assertSee('Aguardando o próximo ciclo do agente')
            ->assertSee('Solicitação')
            ->assertSee('Download/validação')
            ->assertSee('Resultado')
            ->assertSee('Tempo decorrido')
            ->assertSee('Continuar em segundo plano')
            ->assertSee('Sem tocar no BIND')
            ->assertDontSee('data-agent-upgrade-percent', false);
    }

    public function test_upgrade_status_exposes_factual_timing_and_agent_connectivity(): void
    {
        $context = $this->context();
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'authorized')
            ->assertJsonPath('agent_online', true)
            ->assertJsonPath('requested_at', $operation->authorized_at->toIso8601String())
            ->assertJsonPath('agent_last_seen_at', $context['agent']->last_seen_at->toIso8601String());
    }

    public function test_upgrade_status_shows_offline_agent_without_faking_progress(): void
    {
        $context = $this->context();
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);
        $context['server']->update(['agent_status' => 'offline']);
        $context['agent']->update(['last_seen_at' => now()->subMinutes(9)]);

        $response = $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('agent_online', false);

        $this->assertNotNull($response->json('agent_last_seen_at'));
    }

    public function test_upgrade_succeeded_without_new_heartbeat_awaits_confirmation(): void
    {
        $context = $this->context();
        $availableVersion = AgentArtifact::availableVersion();
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'succeeded',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinute(),
            'completed_at' => now(),
            'result' => [
                'binary_changed' => true,
                'units_changed' => false,
                'previous_version' => '0.5.0',
                'changed' => true,
            ],
        ]);
        $context['agent']->update(['metadata' => ['agent_version' => '0.5.0']]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.previous_version', '0.5.0')
            ->assertJsonPath('result.binary_changed', true)
            ->assertJsonPath('installed_version', '0.5.0')
            ->assertJsonPath('target_version', $availableVersion)
            ->assertJsonPath('version_confirmed', false);

        $this->assertSame('succeeded', $operation->fresh()->status);
    }

    public function test_upgrade_succeeded_status_confirms_once_heartbeat_matches_target(): void
    {
        $context = $this->context();
        $availableVersion = AgentArtifact::availableVersion();
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'succeeded',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinute(),
            'completed_at' => now(),
            'result' => [
                'binary_changed' => true,
                'units_changed' => false,
                'previous_version' => '0.5.0',
                'changed' => true,
            ],
        ]);
        $context['agent']->update(['metadata' => ['agent_version' => $availableVersion]]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('installed_version', $availableVersion)
            ->assertJsonPath('target_version', $availableVersion)
            ->assertJsonPath('version_confirmed', true);
    }

    public function test_upgrade_result_confirms_version_before_next_heartbeat(): void
    {
        $context = $this->context();
        $availableVersion = AgentArtifact::availableVersion();
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'succeeded',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinute(),
            'completed_at' => now(),
            'result' => [
                'binary_changed' => true,
                'changed' => true,
                'previous_version' => '0.5.0',
                'installed_version' => $availableVersion,
            ],
        ]);
        $context['agent']->update(['metadata' => ['agent_version' => '0.5.0']]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('installed_version', $availableVersion)
            ->assertJsonPath('version_confirmed', true);
    }

    public function test_upgrade_no_op_reinstall_is_confirmed_without_waiting_for_heartbeat(): void
    {
        $context = $this->context();
        $availableVersion = AgentArtifact::availableVersion();
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'succeeded',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now()->subMinute(),
            'completed_at' => now(),
            'result' => [
                'binary_changed' => false,
                'units_changed' => false,
                'previous_version' => $availableVersion,
                'changed' => false,
            ],
        ]);
        $context['agent']->update(['metadata' => ['agent_version' => $availableVersion]]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('result.changed', false)
            ->assertJsonPath('version_confirmed', true);
    }

    public function test_agent_page_shows_up_to_date_status_without_primary_cta(): void
    {
        $context = $this->context();
        $availableVersion = AgentArtifact::availableVersion();
        $context['agent']->update(['metadata' => ['agent_version' => $availableVersion]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Atualizado')
            ->assertSee('Atualizar agente')
            ->assertDontSee('Nenhuma versão disponível foi informada pelo backend')
            ->assertDontSee('button button-primary" data-agent-upgrade-start', false);
    }

    public function test_agent_page_shows_update_available_with_target_version_cta(): void
    {
        $context = $this->context();
        $context['agent']->update(['metadata' => ['agent_version' => '0.1.0']]);
        $availableVersion = AgentArtifact::availableVersion();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Atualização disponível')
            ->assertSee("Atualizar para {$availableVersion}")
            ->assertSee('button button-primary" data-agent-upgrade-start', false);
    }

    public function test_target_version_cannot_be_manipulated_by_the_client(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.agent.upgrade', $context['server']), [
                'target_version' => '99.0.0',
                'available_version' => '99.0.0',
            ])
            ->assertOk();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('target_version', AgentArtifact::availableVersion())
            ->assertJsonPath('available_version', AgentArtifact::availableVersion());
    }

    public function test_upgrade_failed_state_uses_sanitized_modal_copy_and_cross_tenant_status_is_hidden(): void
    {
        $context = $this->context();
        $foreign = $this->context('Tenant estrangeiro');
        DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'failed',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
            'error' => 'Traceback: /usr/local/sbin/dns-center-agent segredo interno',
        ]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'O agente não conseguiu concluir a atualização.');

        $this->actingAs($foreign['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertNotFound();
    }

    public function test_agent_report_processes_upgrade_agent_action(): void
    {
        $context = $this->context();
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'upgrade_agent',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ],
        )->assertOk();

        $response = $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'succeeded',
                'result' => [
                    'binary_changed' => true,
                    'units_changed' => false,
                    'changed' => true,
                    'installed_version' => AgentArtifact::availableVersion(),
                ],
            ],
        );

        $response->assertOk();
        $this->assertSame('succeeded', $operation->fresh()->status);

        $status = $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']));
        $status->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('version_confirmed', true);
    }

    public function test_report_preserves_diagnostics_paths_but_redacts_secrets_and_unknown_keys(): void
    {
        $context = $this->context();
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'apply_zones',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ],
        )->assertOk();

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'failed',
                'error' => 'Validação final falhou',
                'result' => [
                    'rolled_back' => true,
                    'unexpected_key' => 'should be dropped',
                    'diagnostics' => [
                        'command' => 'named-checkconf',
                        'returncode' => 1,
                        'stderr' => "/etc/bind/named.conf.local:1: zone 'example.com' already exists, token=abc123",
                        'stdout' => '',
                        'unexpected_diagnostics_key' => 'also dropped',
                    ],
                ],
            ],
        )->assertOk();

        $stored = $operation->fresh()->result;

        $this->assertArrayNotHasKey('unexpected_key', $stored);
        $this->assertArrayHasKey('diagnostics', $stored);
        $this->assertArrayNotHasKey('unexpected_diagnostics_key', $stored['diagnostics']);
        $this->assertSame('named-checkconf', $stored['diagnostics']['command']);
        $this->assertStringContainsString(
            '/etc/bind/named.conf.local:1',
            $stored['diagnostics']['stderr'],
        );
        $this->assertStringContainsString(
            'token=[removido]',
            $stored['diagnostics']['stderr'],
        );
        $this->assertStringNotContainsString('abc123', $stored['diagnostics']['stderr']);
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
            'two_factor_secret' => 'encrypted-placeholder',
            'two_factor_confirmed_at' => now(),
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => $role, 'status' => 'active', 'is_default' => true,
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
