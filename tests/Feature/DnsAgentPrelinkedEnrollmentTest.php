<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollmentCode;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentPrelinkedEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_two_factor_issues_hashed_server_specific_one_time_code(): void
    {
        [$organization, $admin] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($admin)->post(route('servers.agent.enrollment-codes.store', $server));
        $response->assertRedirect(route('servers.agent.show', $server))->assertSessionHas('issued_enrollment_code');
        $plain = session('issued_enrollment_code');
        $code = DnsAgentEnrollmentCode::query()->sole();

        $this->assertSame($server->id, $code->dns_server_id);
        $this->assertSame($organization->id, $code->organization_id);
        $this->assertSame(hash('sha256', $plain), $code->code_hash);
        $this->assertNotSame($plain, $code->code_hash);
        $this->assertTrue($code->expires_at->isFuture());
        $this->assertStringNotContainsString($plain, (string) $code->toJson());
        $this->assertDatabaseHas('security_audits', ['event' => 'agent.enrollment_code_issued']);
        $this->assertStringNotContainsString($plain, implode('|', SecurityAudit::query()->pluck('reason')->all()));
    }

    public function test_viewer_and_admin_without_two_factor_cannot_issue_code(): void
    {
        config()->set('security.admin_2fa.required', true);
        config()->set('security.admin_2fa.required', true);
        [$organization, $viewer] = $this->user('viewer', true);
        [, $adminWithoutTwoFactor] = $this->user('organization_admin', false, $organization);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($viewer)->post(route('servers.agent.enrollment-codes.store', $server))->assertForbidden();
        $this->actingAs($adminWithoutTwoFactor)->post(route('servers.agent.enrollment-codes.store', $server))
            ->assertRedirect(route('security.two-factor.setup'));
        $this->assertDatabaseCount('dns_agent_enrollment_codes', 0);
    }

    public function test_new_code_revokes_previous_and_active_agent_blocks_silent_reenrollment(): void
    {
        [$organization, $admin] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        $this->actingAs($admin)->post(route('servers.agent.enrollment-codes.store', $server));
        $first = DnsAgentEnrollmentCode::query()->sole();
        $this->post(route('servers.agent.enrollment-codes.store', $server))->assertRedirect();
        $this->assertNotNull($first->fresh()->revoked_at);

        DnsAgent::query()->create([
            'organization_id' => $organization->id, 'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'machine'),
            'token_hash' => hash('sha256', 'credential'), 'registered_at' => now(),
        ]);
        $this->post(route('servers.agent.enrollment-codes.store', $server))->assertConflict();
        $this->assertDatabaseCount('dns_agent_enrollment_codes', 2);
    }

    public function test_code_directly_associates_ambiguous_hostname_and_nat_ip_and_adds_warnings(): void
    {
        [$organization] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns1.conectanetwork.net.br', 'ipv4_address' => '45.162.196.242',
        ]);
        DnsServer::factory()->create(['organization_id' => $organization->id, 'hostname' => 'ns1.tiringa.com.br']);
        DnsServer::factory()->create(['organization_id' => $organization->id, 'hostname' => 'ns1.uniaonetworks.com.br']);
        [$code, $plain] = $this->code($server);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->postJson('/api/agent/install-requests', $this->payload($plain))
            ->assertAccepted()->assertJsonPath('matched', true);

        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame($server->id, $installRequest->dns_server_id);
        $this->assertSame($organization->id, $installRequest->organization_id);
        $this->assertSame($code->id, $installRequest->enrollment_code_id);
        $this->assertSame('panel_code', $installRequest->enrollment_source);
        $this->assertEqualsCanonicalizing(['hostname_mismatch', 'ip_mismatch'], $installRequest->review_warnings);
        $this->assertNotNull($code->fresh()->used_at);
        $this->assertDatabaseCount('dns_agent_install_requests', 1);
    }

    public function test_code_is_single_use_and_expired_or_revoked_codes_fail(): void
    {
        [$organization] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        [$used, $usedPlain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($usedPlain))->assertAccepted();
        $this->postJson('/api/agent/install-requests', $this->payload($usedPlain))->assertUnprocessable();

        [$expired, $expiredPlain] = $this->code($server, now()->subMinute());
        $this->postJson('/api/agent/install-requests', $this->payload($expiredPlain))->assertUnprocessable();
        [$revoked, $revokedPlain] = $this->code($server);
        $revoked->update(['revoked_at' => now()]);
        $this->postJson('/api/agent/install-requests', $this->payload($revokedPlain))->assertUnprocessable();
    }

    public function test_prelinked_request_remains_pending_until_separate_approval(): void
    {
        [$organization, $admin] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        [, $plain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($plain))->assertAccepted();
        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame('pending', $installRequest->status);
        $this->assertDatabaseCount('dns_agents', 0);

        $this->actingAs($admin)->post(route('servers.agent.install-requests.approve', [$server, $installRequest]))->assertRedirect();
        $this->assertSame('approved', $installRequest->fresh()->status);
        $this->assertDatabaseCount('dns_agents', 1);
    }

    public function test_admin_from_another_organization_cannot_issue_code_for_foreign_server(): void
    {
        [, $admin] = $this->user('organization_admin', true);
        $otherOrganization = Organization::factory()->create();
        $foreignServer = DnsServer::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($admin)
            ->post(route('servers.agent.enrollment-codes.store', $foreignServer))
            ->assertNotFound();
        $this->assertDatabaseCount('dns_agent_enrollment_codes', 0);
    }

    public function test_prelinked_request_never_appears_in_legacy_unassigned_list(): void
    {
        [$organization, $admin] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        [, $plain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($plain))->assertAccepted();

        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame('panel_code', $installRequest->enrollment_source);
        $this->assertNotNull($installRequest->dns_server_id);

        $this->actingAs($admin)->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('Solicitações de agente não associadas')
            ->assertDontSee(substr($installRequest->request_id, 0, 8));
    }

    public function test_new_request_id_from_same_agent_supersedes_stale_pending_row(): void
    {
        [$organization] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        $agentUuid = (string) Str::uuid();

        DnsAgentInstallRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'request_token_hash' => hash('sha256', Str::random(64)),
            'agent_uuid' => $agentUuid,
            'fingerprint' => hash('sha256', str_repeat('a', 64)),
            'reported_hostname' => 'ns1',
            'status' => 'pending',
            'enrollment_source' => 'legacy',
            'expires_at' => now()->addHours(24),
        ]);

        [$code, $plain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($plain, $agentUuid))
            ->assertAccepted()->assertJsonPath('matched', true);

        $this->assertDatabaseCount('dns_agent_install_requests', 1);
        $installRequest = DnsAgentInstallRequest::query()->sole();
        $this->assertSame($agentUuid, $installRequest->agent_uuid);
        $this->assertSame($server->id, $installRequest->dns_server_id);
        $this->assertSame('panel_code', $installRequest->enrollment_source);
        $this->assertSame('pending', $installRequest->status);
    }

    public function test_unclaimed_approval_blocks_new_request_for_same_agent(): void
    {
        [$organization] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        $agentUuid = (string) Str::uuid();

        DnsAgentInstallRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'request_token_hash' => hash('sha256', Str::random(64)),
            'agent_uuid' => $agentUuid,
            'fingerprint' => hash('sha256', str_repeat('a', 64)),
            'reported_hostname' => 'ns1',
            'status' => 'approved',
            'claimed_at' => null,
            'agent_token' => 'still-pending-pickup',
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'enrollment_source' => 'panel_code',
            'expires_at' => now()->addHours(24),
        ]);

        [, $plain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($plain, $agentUuid))
            ->assertConflict();
        $this->assertDatabaseCount('dns_agent_install_requests', 1);
    }

    public function test_admin_can_cancel_unclaimed_approval_to_unblock_new_enrollment(): void
    {
        [$organization, $admin] = $this->user('organization_admin', true);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id]);
        $agentUuid = (string) Str::uuid();

        $installRequest = DnsAgentInstallRequest::query()->create([
            'request_id' => (string) Str::uuid(),
            'request_token_hash' => hash('sha256', Str::random(64)),
            'agent_uuid' => $agentUuid,
            'fingerprint' => hash('sha256', str_repeat('a', 64)),
            'reported_hostname' => 'ns1',
            'status' => 'approved',
            'approved_at' => now(),
            'claimed_at' => null,
            'agent_token' => 'still-pending-pickup',
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'enrollment_source' => 'panel_code',
            'expires_at' => now()->addHours(24),
        ]);

        $this->actingAs($admin)
            ->get(route('servers.agent.show', $server))
            ->assertOk()
            ->assertSee('Aguardando retirada pelo agente')
            ->assertSee('Cancelar aprovação');

        $this->actingAs($admin)
            ->post(route('servers.agent.install-requests.reject', [$server, $installRequest]))
            ->assertRedirect(route('servers.agent.show', $server));

        $this->assertSame('rejected', $installRequest->fresh()->status);

        [, $plain] = $this->code($server);
        $this->postJson('/api/agent/install-requests', $this->payload($plain, $agentUuid))
            ->assertSuccessful();
        $this->assertDatabaseCount('dns_agent_install_requests', 1);
        $this->assertSame('pending', $installRequest->fresh()->status);
    }

    private function code(DnsServer $server, $expiresAt = null): array
    {
        $plain = Str::random(48);
        $code = DnsAgentEnrollmentCode::query()->create([
            'organization_id' => $server->organization_id, 'dns_server_id' => $server->id,
            'code_hash' => hash('sha256', $plain), 'expires_at' => $expiresAt ?? now()->addMinutes(15),
        ]);

        return [$code, $plain];
    }

    private function payload(string $code, ?string $agentUuid = null): array
    {
        return [
            'request_id' => (string) Str::uuid(), 'request_token' => Str::random(64),
            'agent_uuid' => $agentUuid ?? (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64),
            'hostname' => 'ns1', 'agent_version' => '0.6.0', 'enrollment_code' => $code,
        ];
    }

    private function user(string $role, bool $twoFactor, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id, 'must_change_password' => false,
            'two_factor_secret' => $twoFactor ? 'encrypted-placeholder' : null,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ]);
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active', 'is_default' => true]);

        return [$organization, $user];
    }
}
