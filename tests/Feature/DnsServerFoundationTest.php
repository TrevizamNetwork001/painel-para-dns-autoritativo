<?php

namespace Tests\Feature;

use App\Models\DnsServer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsServerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_server_belongs_to_an_organization(): void
    {
        $organization = Organization::factory()->create();

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->assertTrue(
            $server->organization->is($organization)
        );
    }

    public function test_servers_can_be_isolated_by_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        DnsServer::factory()->create([
            'organization_id' => $organizationA->id,
            'hostname' => 'ns1.empresa-a.test',
        ]);

        DnsServer::factory()->create([
            'organization_id' => $organizationB->id,
            'hostname' => 'ns1.empresa-b.test',
        ]);

        $servers = DnsServer::query()
            ->forOrganization($organizationA->id)
            ->get();

        $this->assertCount(1, $servers);
        $this->assertSame(
            'ns1.empresa-a.test',
            $servers->first()->hostname,
        );
    }

    public function test_server_can_store_ipv4_and_ipv6(): void
    {
        $server = DnsServer::factory()->create([
            'ipv4_address' => '192.0.2.53',
            'ipv6_address' => '2001:db8::53',
        ]);

        $this->assertSame(
            '192.0.2.53',
            $server->ipv4_address,
        );

        $this->assertSame(
            '2001:db8::53',
            $server->ipv6_address,
        );
    }

    public function test_new_server_starts_without_agent_contact(): void
    {
        $server = DnsServer::factory()->create([
            'status' => 'pending',
            'last_seen_at' => null,
        ]);

        $this->assertFalse($server->hasAgentContact());
        $this->assertFalse($server->isOnline());
    }
}
