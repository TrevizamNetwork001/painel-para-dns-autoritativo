<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsServerTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_transfers_server_registration_and_revokes_agent(): void
    {
        [$admin, $source] = $this->member('organization_admin', true);
        $destination = Organization::factory()->create(['name' => 'Cliente Legado']);
        $server = DnsServer::factory()->create([
            'organization_id' => $source->id,
            'name' => 'NS Cliente Legado',
            'hostname' => 'ns1.legacy.example',
            'status' => 'online',
            'agent_status' => 'online',
            'last_seen_at' => now(),
        ]);
        $agent = DnsAgent::query()->create([
            'organization_id' => $source->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => 'test-fingerprint',
            'token_hash' => hash('sha256', 'test-token'),
            'registered_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('servers.transfer.store', $server), [
            'organization_id' => $destination->id,
            'confirmation' => 'TRANSFERIR ns1.legacy.example',
        ])->assertRedirect(route('servers.index'));

        $server->refresh();
        $this->assertFalse($server->enabled);
        $this->assertSame('transferred', $server->status);
        $this->assertSame('blocked', $server->agent_status);
        $this->assertNotNull($agent->fresh()->revoked_at);

        $this->assertDatabaseHas('dns_servers', [
            'organization_id' => $destination->id,
            'name' => 'NS Cliente Legado',
            'hostname' => 'ns1.legacy.example',
            'status' => 'pending',
            'agent_status' => 'not_installed',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'dns.server_transferred',
            'organization_id' => $source->id,
            'result' => 'success',
        ]);
    }

    public function test_non_platform_admin_cannot_transfer_server(): void
    {
        [$admin, $source] = $this->member('organization_admin');
        $destination = Organization::factory()->create();
        $server = DnsServer::factory()->create(['organization_id' => $source->id]);

        $this->actingAs($admin)->post(route('servers.transfer.store', $server), [
            'organization_id' => $destination->id,
            'confirmation' => 'TRANSFERIR '.$server->hostname,
        ])->assertForbidden();

        $this->assertDatabaseCount('dns_servers', 1);
    }

    public function test_transferred_server_is_archived_and_cannot_be_reactivated(): void
    {
        [$admin, $source] = $this->member('organization_admin', true);
        $server = DnsServer::factory()->create([
            'organization_id' => $source->id,
            'name' => 'Servidor antigo',
            'status' => 'transferred',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Servidores arquivados')
            ->assertSee('Servidor antigo')
            ->assertSee('Consultar histórico');

        $this->actingAs($admin)
            ->patch(route('servers.status', $server))
            ->assertConflict();

        $this->assertFalse($server->fresh()->enabled);
    }

    public function test_transfer_requires_exact_confirmation_and_unique_destination_hostname(): void
    {
        [$admin, $source] = $this->member('organization_admin', true);
        $destination = Organization::factory()->create();
        $server = DnsServer::factory()->create([
            'organization_id' => $source->id,
            'hostname' => 'ns1.example.test',
        ]);

        $this->actingAs($admin)->post(route('servers.transfer.store', $server), [
            'organization_id' => $destination->id,
            'confirmation' => 'incorreta',
        ])->assertSessionHasErrors('confirmation');

        DnsServer::factory()->create([
            'organization_id' => $destination->id,
            'hostname' => $server->hostname,
        ]);

        $this->actingAs($admin)->post(route('servers.transfer.store', $server), [
            'organization_id' => $destination->id,
            'confirmation' => 'TRANSFERIR '.$server->hostname,
        ])->assertSessionHasErrors('organization_id');

        $this->assertTrue($server->fresh()->enabled);
        $this->assertDatabaseCount('security_audits', 0);
    }

    private function member(string $role, bool $platformAdmin = false): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
            'is_platform_admin' => $platformAdmin,
            'two_factor_secret' => $platformAdmin ? 'encrypted-placeholder' : null,
            'two_factor_confirmed_at' => $platformAdmin ? now() : null,
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $organization];
    }
}
