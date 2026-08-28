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

    public function test_upgrade_succeeded_status_reports_version_change_when_confirmed(): void
    {
        $context = $this->context();
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
        $context['agent']->update(['metadata' => ['agent_version' => '0.6.0']]);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('result.previous_version', '0.5.0')
            ->assertJsonPath('result.binary_changed', true)
            ->assertJsonPath('current_agent_version', '0.6.0');

        $this->assertSame('succeeded', $operation->fresh()->status);
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
                'result' => ['binary_changed' => true, 'units_changed' => false, 'changed' => true],
            ],
        );

        $response->assertOk();
        $this->assertSame('succeeded', $operation->fresh()->status);

        $status = $this->actingAs($context['admin'])
            ->getJson(route('servers.agent.upgrade.status', $context['server']));
        $status->assertOk()->assertJsonPath('status', 'succeeded');
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
