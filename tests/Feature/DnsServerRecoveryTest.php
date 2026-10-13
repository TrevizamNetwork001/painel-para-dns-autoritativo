<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsServerRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_requeues_latest_published_zone_without_changing_serial(): void
    {
        $context = $this->context();
        [$zone, $oldVersion, $latestVersion] = $this->publishedZone($context, 'example.com');
        $zone->servers()->attach($context['server']->id, ['role' => 'primary']);

        $publication = DnsAgentPublication::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_version_id' => $latestVersion->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'status' => 'applied',
            'installed_version' => 2,
            'reported_serial' => 2026101302,
            'serial_confirmed_at' => now(),
            'last_apply_at' => now(),
        ]);

        $this->actingAs($context['admin'])
            ->post(route('servers.recovery.store', $context['server']), [
                'confirmation' => 'RECUPERAR '.Str::upper($context['server']->name),
            ])
            ->assertRedirect(route('servers.agent.show', $context['server']))
            ->assertSessionHas('status');

        $publication->refresh();
        $this->assertSame('pending', $publication->status);
        $this->assertNull($publication->installed_version);
        $this->assertNull($publication->reported_serial);
        $this->assertDatabaseMissing('dns_agent_publications', [
            'dns_zone_version_id' => $oldVersion->id,
            'dns_server_id' => $context['server']->id,
        ]);
        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'apply_zones',
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('dns_audit_logs', [
            'action' => 'server.recovery_queued',
            'record_name' => $context['server']->name,
            'new_value' => '1',
        ]);
        $this->assertSame(2026101302, $zone->fresh()->serial);
    }

    public function test_recovery_targets_the_reenrolled_active_agent(): void
    {
        $context = $this->context();
        [$zone, , $latestVersion] = $this->publishedZone($context, 'new-agent.example');
        $zone->servers()->attach($context['server']->id, ['role' => 'primary']);
        $newAgentUuid = (string) Str::uuid();
        $context['agent']->update([
            'agent_uuid' => $newAgentUuid,
            'token_hash' => hash('sha256', Str::random(96)),
            'revoked_at' => null,
        ]);

        $this->actingAs($context['admin'])
            ->post(route('servers.recovery.store', $context['server']), [
                'confirmation' => 'RECUPERAR '.Str::upper($context['server']->name),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_agent_publications', [
            'dns_zone_version_id' => $latestVersion->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'status' => 'pending',
        ]);
    }

    public function test_recovery_requires_exact_confirmation(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('servers.recovery.store', $context['server']), [
                'confirmation' => 'RECUPERAR',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('dns_bind_operations', 0);
    }

    public function test_viewer_cannot_recover_server(): void
    {
        $context = $this->context();
        $viewer = $this->member($context['organization'], 'viewer');

        $this->actingAs($viewer)
            ->post(route('servers.recovery.store', $context['server']), [
                'confirmation' => 'RECUPERAR '.Str::upper($context['server']->name),
            ])
            ->assertForbidden();
    }

    private function publishedZone(array $context, string $name): array
    {
        $zone = DnsZone::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => $name,
            'kind' => 'primary',
            'serial' => 2026101302,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.example.com',
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 2,
            'enabled' => true,
        ]);
        $oldVersion = $zone->versions()->create([
            'organization_id' => $context['organization']->id,
            'created_by' => $context['admin']->id,
            'version' => 1,
            'serial' => 2026101301,
            'reason' => 'Publicação anterior.',
            'snapshot' => [],
        ]);
        $latestVersion = $zone->versions()->create([
            'organization_id' => $context['organization']->id,
            'created_by' => $context['admin']->id,
            'version' => 2,
            'serial' => 2026101302,
            'reason' => 'Zona publicada.',
            'snapshot' => [],
        ]);

        return [$zone, $oldVersion, $latestVersion];
    }

    private function context(): array
    {
        $organization = Organization::factory()->create();
        $admin = $this->member($organization, 'organization_admin');
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'União-01',
            'role' => 'primary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);
        $agent = $this->agent($organization, $server);

        return compact('organization', 'admin', 'server', 'agent');
    }

    private function agent(Organization $organization, DnsServer $server): DnsAgent
    {
        return DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', Str::random(96)),
            'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);
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
