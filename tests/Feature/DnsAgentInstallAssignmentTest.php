<?php

namespace Tests\Feature;

use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentInstallAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_reviews_ambiguous_request_and_allowed_candidates(): void
    {
        [$organization, $admin] = $this->organizationUser('organization_admin', true);
        [$foreignOrganization, $foreignAdmin] = $this->organizationUser('organization_admin', true);
        [, $viewer] = $this->organizationUser('viewer', false, $organization);
        $first = $this->server($organization, 'ns1.tiringa.com.br');
        $second = $this->server($organization, 'ns1.conectanetwork.net.br');
        $foreign = $this->server($foreignOrganization, 'ns2.foreign.test');
        $disabled = $this->server($organization, 'ns1.disabled.test', false);
        $installRequest = $this->installRequest();

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Solicitações de agente não associadas')
            ->assertSee(substr($installRequest->request_id, 0, 8));

        $this->actingAs($admin)
            ->get(route(
                'servers.agent.install-requests.assignment.show',
                $installRequest,
            ))
            ->assertOk()
            ->assertSee($first->hostname)
            ->assertSee($second->hostname)
            ->assertDontSee($foreign->hostname)
            ->assertDontSee($disabled->hostname)
            ->assertSee('IP observado')
            ->assertSee('172.18.0.1')
            ->assertDontSee('request-secret');

        $this->actingAs($viewer)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('Solicitações de agente não associadas');
        $this->actingAs($viewer)
            ->get(route(
                'servers.agent.install-requests.assignment.show',
                $installRequest,
            ))
            ->assertForbidden();
        $this->actingAs($foreignAdmin)
            ->get(route(
                'servers.agent.install-requests.assignment.show',
                $installRequest,
            ))
            ->assertNotFound();
    }

    public function test_assignment_requires_two_factor_and_explicit_confirmation(): void
    {
        config()->set('security.admin_2fa.required', true);
        [$organization, $admin] = $this->organizationUser('organization_admin', false);
        $server = $this->server($organization, 'ns1.example.test');
        $installRequest = $this->installRequest();

        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $this->assignmentPayload($installRequest, $server))
            ->assertRedirect(route('security.two-factor.setup'));

        $this->assertNull($installRequest->fresh()->dns_server_id);

        $admin->forceFill([
            'two_factor_secret' => 'encrypted-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $payload = $this->assignmentPayload($installRequest, $server);
        $payload['confirmation'] = 'CONFIRMACAO INCORRETA';
        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $payload)
            ->assertSessionHasErrors('confirmation');

        $this->assertNull($installRequest->fresh()->dns_server_id);
    }

    public function test_explicit_assignment_preserves_request_and_does_not_approve(): void
    {
        [$organization, $admin] = $this->organizationUser('organization_admin', true);
        $this->server($organization, 'ns1.tiringa.com.br');
        $server = $this->server($organization, 'ns1.conectanetwork.net.br');
        $installRequest = $this->installRequest();
        $originalRequestId = $installRequest->request_id;
        $originalFingerprint = $installRequest->fingerprint;
        $originalTokenHash = $installRequest->request_token_hash;
        $originalCreatedAt = $installRequest->created_at;

        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $this->assignmentPayload($installRequest, $server))
            ->assertRedirect(route('servers.agent.show', $server));

        $fresh = $installRequest->fresh();
        $this->assertSame($server->id, $fresh->dns_server_id);
        $this->assertSame($organization->id, $fresh->organization_id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($originalRequestId, $fresh->request_id);
        $this->assertSame($originalFingerprint, $fresh->fingerprint);
        $this->assertSame($originalTokenHash, $fresh->request_token_hash);
        $this->assertTrue($originalCreatedAt->equalTo($fresh->created_at));
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->agent_token);
        $this->assertDatabaseCount('dns_agents', 0);

        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.enrollment_assigned',
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'result' => 'success',
        ]);
        $audit = SecurityAudit::query()
            ->where('event', 'agent.enrollment_assigned')
            ->sole();
        $this->assertStringNotContainsString('request-secret', (string) $audit->reason);
        $this->assertStringNotContainsString($originalTokenHash, (string) $audit->reason);
    }

    public function test_invalid_candidate_and_cross_tenant_assignment_are_blocked(): void
    {
        [$organization, $admin] = $this->organizationUser('organization_admin', true);
        [$foreignOrganization] = $this->organizationUser('organization_admin', true);
        $server = $this->server($organization, 'ns1.example.test');
        $foreign = $this->server($foreignOrganization, 'ns2.foreign.test');
        $installRequest = $this->installRequest();

        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $this->assignmentPayload($installRequest, $foreign))
            ->assertStatus(422);

        $this->assertNull($installRequest->fresh()->dns_server_id);
        $this->assertNotSame($server->id, $foreign->id);
    }

    public function test_expired_approved_and_already_assigned_requests_cannot_be_assigned(): void
    {
        [$organization, $admin] = $this->organizationUser('organization_admin', true);
        $server = $this->server($organization, 'ns1.example.test');

        foreach ([
            ['expires_at' => now()->subMinute()],
            ['status' => 'approved'],
            [
                'dns_server_id' => $server->id,
                'organization_id' => $organization->id,
            ],
        ] as $overrides) {
            $installRequest = $this->installRequest($overrides);

            $this->actingAs($admin)
                ->post(route(
                    'servers.agent.install-requests.assignment.store',
                    $installRequest,
                ), $this->assignmentPayload($installRequest, $server))
                ->assertStatus(409);
        }

        $this->assertDatabaseCount('security_audits', 0);
    }

    public function test_second_assignment_attempt_cannot_change_selected_server(): void
    {
        [$organization, $admin] = $this->organizationUser('organization_admin', true);
        $first = $this->server($organization, 'ns1.first.test');
        $second = $this->server($organization, 'ns1.second.test');
        $installRequest = $this->installRequest();

        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $this->assignmentPayload($installRequest, $first))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route(
                'servers.agent.install-requests.assignment.store',
                $installRequest,
            ), $this->assignmentPayload($installRequest, $second))
            ->assertStatus(409);

        $this->assertSame($first->id, $installRequest->fresh()->dns_server_id);
        $this->assertDatabaseCount('security_audits', 1);
    }

    private function installRequest(array $overrides = []): DnsAgentInstallRequest
    {
        return DnsAgentInstallRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'request_token_hash' => hash('sha256', 'request-secret'),
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'machine-fingerprint'),
            'reported_hostname' => 'ns1',
            'registered_ip' => '172.18.0.1',
            'agent_version' => '0.5.0',
            'operating_system' => 'Debian GNU/Linux',
            'operating_system_version' => '13',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }

    private function server(
        Organization $organization,
        string $hostname,
        bool $enabled = true,
    ): DnsServer {
        return DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => $hostname,
            'enabled' => $enabled,
        ]);
    }

    private function assignmentPayload(
        DnsAgentInstallRequest $installRequest,
        DnsServer $server,
    ): array {
        return [
            'dns_server_id' => $server->id,
            'confirmation' => 'ASSOCIAR '.substr($installRequest->request_id, 0, 8)
                .' AO SERVIDOR '.Str::upper($server->hostname),
        ];
    }

    private function organizationUser(
        string $role,
        bool $withTwoFactor,
        ?Organization $organization = null,
    ): array {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'must_change_password' => false,
            'two_factor_secret' => $withTwoFactor
                ? 'encrypted-placeholder'
                : null,
            'two_factor_confirmed_at' => $withTwoFactor ? now() : null,
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$organization, $user];
    }
}
