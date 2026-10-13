<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsFirewallPolicyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_saves_and_authorizes_validation_without_apply_action(): void
    {
        [$admin, $server] = $this->context();
        $payload = [
            'administrative_acls' => "192.0.2.10/32\n2001:db8::10/128",
            'administrative_ports' => 'tcp/22',
            'public_ports' => "tcp/53\nudp/53",
        ];

        $this->actingAs($admin)->put(route('servers.firewall.update', $server), $payload)->assertRedirect();
        $this->actingAs($admin)->postJson(route('servers.firewall.validate', $server))->assertOk()->assertJsonPath('status', 'authorized');

        $operation = DnsBindOperation::query()->sole();
        $this->assertSame('validate_firewall_policy', $operation->action);
        $this->assertSame('inet dns_center', $operation->params['table']);
        $this->assertSame(['dns_tcp_udp_53' => true, 'outbound_unchanged' => true], $operation->params['invariants']);
        $this->assertDatabaseMissing('dns_bind_operations', ['action' => 'apply_firewall_policy']);
    }

    public function test_open_admin_acl_is_rejected(): void
    {
        [$admin, $server] = $this->context();
        $this->actingAs($admin)->from(route('servers.firewall.show', $server))->put(route('servers.firewall.update', $server), [
            'administrative_acls' => '0.0.0.0/0',
            'administrative_ports' => 'tcp/22',
            'public_ports' => 'udp/53',
        ])->assertSessionHasErrors('administrative_acls');
        $this->assertDatabaseCount('dns_firewall_policies', 0);
    }

    private function context(): array
    {
        $organization = Organization::factory()->create(['status' => 'active', 'is_default' => true]);
        $admin = User::factory()->create(['current_organization_id' => $organization->id, 'status' => 'active', 'must_change_password' => false]);
        $admin->organizations()->attach($organization->id, ['role' => 'organization_admin', 'status' => 'active', 'is_default' => true]);
        $server = DnsServer::factory()->create(['organization_id' => $organization->id, 'status' => 'online', 'agent_status' => 'online']);
        DnsAgent::query()->create([
            'organization_id' => $organization->id, 'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(), 'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', Str::random(96)), 'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1', 'registered_at' => now(), 'last_seen_at' => now(), 'metadata' => [],
        ]);

        return [$admin, $server];
    }
}
