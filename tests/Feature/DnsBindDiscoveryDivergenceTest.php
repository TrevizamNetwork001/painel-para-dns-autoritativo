<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindIgnoredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindDiscoveryDivergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_flags_serial_mismatch_and_absence_between_servers(): void
    {
        $context = $this->context();
        $peer = $this->peerServer($context, 'ns2-peer');

        $this->discover($context['server'], $context, [
            ['name' => 'igual.example', 'serial' => 10],
            ['name' => 'serial.example', 'serial' => 20],
            ['name' => 'so-aqui.example', 'serial' => 30],
        ]);
        $this->discover($peer, $context, [
            ['name' => 'igual.example', 'serial' => 10, 'detected_type' => 'secondary'],
            ['name' => 'serial.example', 'serial' => 19],
            ['name' => 'so-la.example', 'serial' => 40],
        ]);

        $response = $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertSee('Sincronizada')
            ->assertSee('Serial diferente em ns2-peer (19)')
            ->assertSee('Ausente em ns2-peer')
            ->assertSee('Presentes em outros servidores, ausentes aqui');

        $response->assertSee('so-la.example');
    }

    public function test_no_peer_comparison_when_other_servers_have_no_discovery(): void
    {
        $context = $this->context();
        $this->peerServer($context, 'ns2-sem-descoberta');
        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertDontSee('Comparação entre servidores')
            ->assertDontSee('Divergente');
    }

    public function test_unrelated_server_in_same_organization_is_not_compared(): void
    {
        $context = $this->context();
        $unrelated = $this->peerServer($context, 'ns1-outro-cliente');

        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);
        $this->discover($unrelated, $context, [['name' => 'outro-cliente.example', 'serial' => 9]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertDontSee('outro-cliente.example')
            ->assertDontSee('Ausente em ns1-outro-cliente')
            ->assertDontSee('Divergente');
    }

    public function test_server_sharing_a_managed_zone_is_compared_even_without_overlapping_discovery(): void
    {
        $context = $this->context();
        $newSlave = $this->peerServer($context, 'ns2-novo');

        $zone = DnsZone::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'a.example',
            'kind' => 'primary',
            'serial' => 1,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.a.example.',
            'soa_rname' => 'hostmaster.a.example.',
            'status' => 'published',
            'version' => 1,
            'enabled' => true,
        ]);
        $zone->servers()->sync([
            $context['server']->id => ['role' => 'primary'],
            $newSlave->id => ['role' => 'secondary'],
        ]);

        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);
        $this->discover($newSlave, $context, [['name' => 'lixo.example', 'serial' => 1]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertSee('Ausente em ns2-novo');
    }

    public function test_peer_of_another_tenant_is_never_compared(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra Organização');

        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);
        $this->discover($foreign['server'], $foreign, [['name' => 'vazou.example', 'serial' => 1]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertDontSee('vazou.example');
    }

    public function test_admin_can_ignore_and_unignore_a_zone_and_it_leaves_the_importable_list(): void
    {
        $context = $this->context();
        $this->discover($context['server'], $context, [['name' => 'legitima.example', 'serial' => 1]]);
        $discovered = DnsBindDiscoveredZone::query()->where('name', 'legitima.example')->sole();

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discovery.ignore', $context['server']), ['zone_name' => 'legitima.example'])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_bind_ignored_zones', [
            'dns_server_id' => $context['server']->id,
            'zone_name' => 'legitima.example',
            'ignored_by' => $context['admin']->id,
        ]);

        $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertOk()
            ->assertSee('Ignorada (legítima)')
            ->assertDontSee('value="'.$discovered->id.'"', false);

        $this->actingAs($context['admin'])
            ->delete(route('servers.bind.discovery.unignore', $context['server']), ['zone_name' => 'legitima.example'])
            ->assertRedirect();

        $this->assertDatabaseMissing('dns_bind_ignored_zones', ['zone_name' => 'legitima.example']);

        $this->assertDatabaseHas('security_audits', ['event' => 'dns.bind_zone_ignored']);
        $this->assertDatabaseHas('security_audits', ['event' => 'dns.bind_zone_unignored']);
    }

    public function test_ignored_zone_cannot_be_imported(): void
    {
        $context = $this->context();
        $this->discover($context['server'], $context, [['name' => 'legitima.example', 'serial' => 1]]);
        $discovered = DnsBindDiscoveredZone::query()->where('name', 'legitima.example')->sole();

        DnsBindIgnoredZone::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'zone_name' => 'legitima.example',
        ]);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discovery.import', $context['server']), ['zone_ids' => [$discovered->id]])
            ->assertRedirect();

        $this->assertSame(0, DnsZone::query()->where('name', 'legitima.example')->count());
    }

    public function test_viewer_cannot_ignore_and_other_tenant_gets_404(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra Organização');
        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);

        $viewer = $this->member($context['organization'], 'viewer');
        $this->actingAs($viewer)
            ->post(route('servers.bind.discovery.ignore', $context['server']), ['zone_name' => 'a.example'])
            ->assertForbidden();

        $this->actingAs($foreign['admin'])
            ->post(route('servers.bind.discovery.ignore', $context['server']), ['zone_name' => 'a.example'])
            ->assertNotFound();

        $this->assertDatabaseCount('dns_bind_ignored_zones', 0);
    }

    public function test_cannot_ignore_a_zone_that_was_never_discovered_on_this_server(): void
    {
        $context = $this->context();
        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discovery.ignore', $context['server']), ['zone_name' => 'inventada.example'])
            ->assertNotFound();
    }

    public function test_imported_zone_cannot_be_ignored(): void
    {
        $context = $this->context();
        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1, 'comparison_state' => 'imported']]);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discovery.ignore', $context['server']), ['zone_name' => 'a.example'])
            ->assertStatus(409);
    }

    public function test_agent_page_flags_discovery_older_than_24_hours_as_stale(): void
    {
        $context = $this->context();
        $operation = $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);
        $operation->forceFill(['completed_at' => now()->subDays(2)])->save();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('class="discovery-stale-hint"', false)
            ->assertSee('Desatualizada (mais de 24h)');
    }

    public function test_agent_page_does_not_flag_recent_discovery_as_stale(): void
    {
        $context = $this->context();
        $this->discover($context['server'], $context, [['name' => 'a.example', 'serial' => 1]]);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertDontSee('class="discovery-stale-hint"', false)
            ->assertDontSee('Desatualizada (mais de 24h)');
    }

    private function discover(DnsServer $server, array $context, array $zones): DnsBindOperation
    {
        $operation = DnsBindOperation::query()->create([
            'organization_id' => $server->organization_id,
            'dns_server_id' => $server->id,
            'dns_agent_id' => $server->agent?->id ?? $context['agent']->id,
            'action' => 'discover_bind_zones',
            'status' => 'succeeded',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
            'completed_at' => now(),
        ]);

        foreach ($zones as $zone) {
            DnsBindDiscoveredZone::query()->create(array_merge([
                'organization_id' => $server->organization_id,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $operation->dns_agent_id,
                'dns_bind_operation_id' => $operation->id,
                'detected_type' => 'primary',
                'detected_syntax' => 'master',
                'file_path' => '/var/cache/bind/'.$zone['name'].'.hosts',
                'node_count' => 1,
                'validation_status' => 'ok',
                'comparison_state' => 'new',
            ], $zone));
        }

        return $operation;
    }

    private function peerServer(array $context, string $name): DnsServer
    {
        $peer = DnsServer::factory()->create([
            'organization_id' => $context['organization']->id,
            'name' => $name,
            'hostname' => $name.'.example',
            'role' => 'secondary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        DnsAgent::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $peer->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', Str::random(96)),
            'reported_hostname' => $peer->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);

        return $peer->fresh();
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
            'name' => $name.' ns1',
            'hostname' => 'ns1.'.Str::slug($name).'.example',
            'role' => 'primary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        $agent = DnsAgent::query()->create([
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

        return compact('organization', 'admin', 'server', 'agent');
    }
}
