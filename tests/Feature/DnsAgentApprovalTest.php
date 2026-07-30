<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Instalação aguardando aprovação')
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
