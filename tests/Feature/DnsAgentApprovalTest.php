<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_is_requested_and_approved_without_activation_code(): void
    {
        config()->set('security.admin_2fa.required', true);
        [$organization, $admin] = $this->organizationAdmin(true);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns1.approval.test',
            'enabled' => true,
        ]);
        $requestId = (string) Str::uuid();
        $requestToken = Str::random(64);
        $agentUuid = (string) Str::uuid();
        $fingerprint = str_repeat('a', 64);

        $this->postJson('/api/agent/install-requests', [
            'request_id' => $requestId,
            'request_token' => $requestToken,
            'agent_uuid' => $agentUuid,
            'fingerprint' => $fingerprint,
            'hostname' => 'NS1.APPROVAL.TEST',
            'agent_version' => '0.4.0',
            'operating_system' => 'Debian',
            'operating_system_version' => '13',
        ])->assertAccepted()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('matched', true);

        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame($server->id, $installRequest->dns_server_id);
        $this->assertNotSame($requestToken, $installRequest->request_token_hash);
        $this->assertNotSame($fingerprint, $installRequest->fingerprint);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Solicitação recebida')
            ->assertDontSee('Código de ativação');

        $this->post(route(
            'servers.agent.install-requests.approve',
            [$server, $installRequest],
        ))->assertRedirect(route('servers.agent.show', $server));

        $agent = DnsAgent::query()->sole();
        $this->assertSame($agentUuid, $agent->agent_uuid);
        $this->assertSame('approved', $installRequest->fresh()->status);
        $this->assertNotNull($installRequest->fresh()->approved_at);
        $encryptedToken = DB::table('dns_agent_install_requests')
            ->where('id', $installRequest->id)
            ->value('agent_token');
        $this->assertNotSame(
            $installRequest->fresh()->agent_token,
            $encryptedToken,
        );
        $this->assertArrayNotHasKey(
            'agent_token',
            $installRequest->fresh()->toArray(),
        );

        $this->postJson('/api/agent/install-requests/status', [
            'request_id' => $requestId,
            'request_token' => Str::random(64),
        ])->assertNotFound();

        $response = $this->postJson('/api/agent/install-requests/status', [
            'request_id' => $requestId,
            'request_token' => $requestToken,
        ])->assertOk()
            ->assertJsonPath('status', 'approved');

        $plainToken = $response->json('agent.token');
        $this->assertIsString($plainToken);
        $this->assertSame(hash('sha256', $plainToken), $agent->token_hash);
        $this->assertNull($installRequest->fresh()->agent_token);

        $this->postJson('/api/agent/install-requests/status', [
            'request_id' => $requestId,
            'request_token' => $requestToken,
        ])->assertGone();

        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.install_approved',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_approval_reuses_admin_two_factor_and_tenant_isolation(): void
    {
        config()->set('security.admin_2fa.required', true);
        [$organization, $admin] = $this->organizationAdmin(false);
        [$foreignOrganization, $foreignAdmin] = $this->organizationAdmin(true);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns2.approval.test',
            'enabled' => true,
        ]);
        $installRequest = DnsAgentInstallRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'request_token_hash' => hash('sha256', Str::random(64)),
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', str_repeat('b', 64)),
            'reported_hostname' => $server->hostname,
            'status' => 'pending',
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.approve',
                [$server, $installRequest],
            ))
            ->assertRedirect(route('security.two-factor.setup'));

        $this->actingAs($foreignAdmin)
            ->post(route(
                'servers.agent.install-requests.approve',
                [$server, $installRequest],
            ))
            ->assertNotFound();

        $this->assertDatabaseCount('dns_agents', 0);
        $this->assertSame('pending', $installRequest->fresh()->status);
        $this->assertNotSame($organization->id, $foreignOrganization->id);
    }

    public function test_unmatched_request_cannot_be_claimed_by_a_tenant(): void
    {
        $this->postJson('/api/agent/install-requests', [
            'request_id' => (string) Str::uuid(),
            'request_token' => Str::random(64),
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('c', 64),
            'hostname' => 'unknown.invalid',
        ])->assertAccepted()
            ->assertJsonPath('matched', false);

        $request = DnsAgentInstallRequest::query()->sole();
        $this->assertNull($request->organization_id);
        $this->assertNull($request->dns_server_id);
        $this->assertDatabaseCount('dns_agents', 0);
    }

    public function test_short_hostname_matches_only_one_fqdn_and_appears_for_its_server(): void
    {
        [$organization, $admin] = $this->organizationAdmin(true);
        [$foreignOrganization] = $this->organizationAdmin(true);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns1.conectanetwork.net.br',
            'ipv4_address' => '45.162.196.242',
            'enabled' => true,
        ]);
        $otherServer = DnsServer::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'hostname' => 'ns2.example.test',
            'enabled' => true,
        ]);
        $requestId = (string) Str::uuid();

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->postJson('/api/agent/install-requests', [
                'request_id' => $requestId,
                'request_token' => Str::random(64),
                'agent_uuid' => (string) Str::uuid(),
                'fingerprint' => str_repeat('d', 64),
                'hostname' => 'ns1',
            ])->assertAccepted()
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('matched', true);

        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame($server->id, $installRequest->dns_server_id);
        $this->assertSame($organization->id, $installRequest->organization_id);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Solicitação recebida');
        $this->actingAs($admin)
            ->get(route('servers.agent.show', $otherServer))
            ->assertNotFound();
    }

    public function test_ip_matches_server_and_existing_unmatched_request_is_recovered(): void
    {
        [$organization, $admin] = $this->organizationAdmin(true);
        $requestId = (string) Str::uuid();
        $requestToken = Str::random(64);
        $agentUuid = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'request_token' => $requestToken,
            'agent_uuid' => $agentUuid,
            'fingerprint' => str_repeat('e', 64),
            'hostname' => 'unmatched-host',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->postJson('/api/agent/install-requests', $payload)
            ->assertAccepted()
            ->assertJsonPath('matched', false);

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'different.example.test',
            'ipv4_address' => '45.162.196.242',
            'enabled' => true,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '45.162.196.242'])
            ->postJson('/api/agent/install-requests', $payload)
            ->assertAccepted()
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('matched', true);

        $this->assertDatabaseCount('dns_agent_install_requests', 1);
        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame($server->id, $installRequest->dns_server_id);
        $this->assertSame($organization->id, $installRequest->organization_id);
        $this->assertSame('45.162.196.242', $installRequest->registered_ip);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Solicitação recebida');
    }

    public function test_ambiguous_short_hostname_does_not_cross_organizations(): void
    {
        [$organization] = $this->organizationAdmin(true);
        [$foreignOrganization] = $this->organizationAdmin(true);

        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns1.example.test',
            'enabled' => true,
        ]);
        DnsServer::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'hostname' => 'ns1.foreign.test',
            'enabled' => true,
        ]);

        $this->postJson('/api/agent/install-requests', [
            'request_id' => (string) Str::uuid(),
            'request_token' => Str::random(64),
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('f', 64),
            'hostname' => 'ns1',
        ])->assertAccepted()
            ->assertJsonPath('matched', false);

        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertNull($installRequest->dns_server_id);
        $this->assertNull($installRequest->organization_id);
    }

    public function test_legacy_activation_routes_are_removed(): void
    {
        $this->postJson('/api/agent/enroll', [
            'activation_code' => 'DNSC-LEGACY',
        ])->assertNotFound();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('dns_agent_enrollments'));
    }

    public function test_official_agent_and_checksum_are_publicly_available(): void
    {
        $this->get(route('install.agent.binary'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/x-python; charset=UTF-8');

        $checksum = $this->get(route('install.agent.checksum'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            hash_file('sha256', base_path('agent/dns-center-agent.py'))
                ."  dns-center-agent.py\n",
            $checksum,
        );

        $installerChecksum = $this
            ->get(route('install.agent.installer-checksum'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            hash_file('sha256', public_path('install/agent_install.sh'))
                ."  agent_install.sh\n",
            $installerChecksum,
        );

        foreach ([
            'dns-center-agent.service',
            'dns-center-agent.timer',
            'dns-center-agent-operation.service',
            'dns-center-agent-operation.timer',
            'dns-center-agent-approval.service',
            'dns-center-agent-approval.timer',
        ] as $artifact) {
            $this->get("/install/{$artifact}")
                ->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

            $this->get("/install/{$artifact}.sha256")
                ->assertOk()
                ->assertSee(
                    hash_file('sha256', base_path("agent/systemd/{$artifact}")),
                );
        }
    }

    public function test_agent_page_is_compatible_before_install_request_migration(): void
    {
        [$organization, $admin] = $this->organizationAdmin(true);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
        ]);
        Schema::drop('dns_agent_install_requests');

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Gerar vínculo')
            ->assertSee('Modo legado / associação manual');

        $this->postJson('/api/agent/install-requests', [])
            ->assertServiceUnavailable()
            ->assertJsonPath('error', 'installation_unavailable');
    }

    private function organizationAdmin(bool $withTwoFactor): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'must_change_password' => false,
            'two_factor_secret' => $withTwoFactor
                ? 'encrypted-placeholder'
                : null,
            'two_factor_confirmed_at' => $withTwoFactor ? now() : null,
        ]);
        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$organization, $admin];
    }
}
