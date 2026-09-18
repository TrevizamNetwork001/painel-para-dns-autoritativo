<?php

namespace Tests\Feature;

use App\Models\DnsServer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsServerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_servers_page(): void
    {
        [$admin, $organization] = $this->admin();

        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS-01',
        ]);

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('Servidores DNS')
            ->assertSee('DNS-01');
    }

    public function test_agent_button_uses_operational_attention_and_failure_indicators(): void
    {
        [$admin, $organization] = $this->admin();

        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS operacional',
            'agent_uuid' => 'b5ca7b7c-9740-4ff1-95e8-7b2cab58ea40',
            'agent_status' => 'online',
            'last_seen_at' => now(),
            'bind_readiness_at' => now(),
            'bind_readiness' => [
                'bind_installed' => true,
                'service' => ['active' => true],
                'listeners' => ['tcp_53' => true, 'udp_53' => true],
            ],
        ]);
        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS aguardando',
            'agent_status' => 'pending',
        ]);
        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS sem agente',
            'agent_uuid' => null,
            'agent_status' => 'not_installed',
        ]);

        $this->actingAs($admin)
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('button-success-soft', false)
            ->assertSee('Agente operacional')
            ->assertSee('button-warning-soft', false)
            ->assertSee('Agente em atenção')
            ->assertSee('button-danger-soft', false)
            ->assertSee('Agente não integrado');
    }

    public function test_admin_can_create_primary_server(): void
    {
        [$admin, $organization] = $this->admin();

        $response = $this
            ->actingAs($admin)
            ->post(route('servers.store'), [
                'name' => 'DNS-01',
                'hostname' => 'NS1.EXEMPLO.COM.BR.',
                'ipv4_address' => '192.0.2.53',
                'ipv6_address' => '2001:db8::53',
                'role' => 'primary',
                'environment' => 'production',
                'notes' => 'Servidor principal',
            ]);

        $response
            ->assertRedirect(route('servers.index'))
            ->assertSessionHas(
                'status',
                'Servidor DNS criado com sucesso.',
            );

        $this->assertDatabaseHas('dns_servers', [
            'organization_id' => $organization->id,
            'name' => 'DNS-01',
            'hostname' => 'ns1.exemplo.com.br',
            'ipv4_address' => '192.0.2.53',
            'ipv6_address' => '2001:db8::53',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'pending',
            'enabled' => true,
        ]);
    }

    public function test_server_requires_at_least_one_ip_address(): void
    {
        [$admin] = $this->admin();

        $this->actingAs($admin)
            ->post(route('servers.store'), [
                'name' => 'DNS sem IP',
                'hostname' => 'ns1.sem-ip.test',
                'ipv4_address' => '',
                'ipv6_address' => '',
                'role' => 'primary',
                'environment' => 'production',
            ])
            ->assertSessionHasErrors([
                'ipv4_address',
                'ipv6_address',
            ]);
    }

    public function test_invalid_ip_addresses_are_rejected(): void
    {
        [$admin] = $this->admin();

        $this->actingAs($admin)
            ->post(route('servers.store'), [
                'name' => 'DNS inválido',
                'hostname' => 'ns1.invalido.test',
                'ipv4_address' => '999.999.999.999',
                'ipv6_address' => 'ipv6-invalido',
                'role' => 'primary',
                'environment' => 'production',
            ])
            ->assertSessionHasErrors([
                'ipv4_address',
                'ipv6_address',
            ]);
    }

    public function test_hostname_must_be_unique_inside_organization(): void
    {
        [$admin, $organization] = $this->admin();

        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'hostname' => 'ns1.exemplo.test',
        ]);

        $this->actingAs($admin)
            ->post(route('servers.store'), [
                'name' => 'DNS duplicado',
                'hostname' => 'ns1.exemplo.test',
                'ipv4_address' => '192.0.2.54',
                'role' => 'secondary',
                'environment' => 'production',
            ])
            ->assertSessionHasErrors('hostname');
    }

    public function test_same_hostname_is_allowed_in_other_organization(): void
    {
        [$admin] = $this->admin();

        $otherOrganization = Organization::factory()->create();

        DnsServer::factory()->create([
            'organization_id' => $otherOrganization->id,
            'hostname' => 'ns1.compartilhado.test',
        ]);

        $this->actingAs($admin)
            ->post(route('servers.store'), [
                'name' => 'DNS local',
                'hostname' => 'ns1.compartilhado.test',
                'ipv4_address' => '192.0.2.55',
                'role' => 'primary',
                'environment' => 'production',
            ])
            ->assertRedirect(route('servers.index'));
    }

    public function test_admin_can_update_server(): void
    {
        [$admin, $organization] = $this->admin();

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS antigo',
            'hostname' => 'ns1.antigo.test',
        ]);

        $this->actingAs($admin)
            ->put(route('servers.update', $server), [
                'name' => 'DNS atualizado',
                'hostname' => 'ns2.exemplo.test',
                'ipv4_address' => '192.0.2.60',
                'ipv6_address' => '',
                'role' => 'secondary',
                'environment' => 'staging',
                'notes' => 'Atualizado',
            ])
            ->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('dns_servers', [
            'id' => $server->id,
            'name' => 'DNS atualizado',
            'hostname' => 'ns2.exemplo.test',
            'ipv4_address' => '192.0.2.60',
            'ipv6_address' => null,
            'role' => 'secondary',
            'environment' => 'staging',
        ]);
    }

    public function test_server_name_rejects_label_copied_from_server_selector(): void
    {
        [$admin, $organization] = $this->admin();
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'DNS original',
            'hostname' => 'ns1.example.test',
        ]);

        $this->actingAs($admin)
            ->put(route('servers.update', $server), [
                'name' => 'DNS original — ns1.example.test',
                'hostname' => 'ns1.example.test',
                'ipv4_address' => '192.0.2.60',
                'ipv6_address' => '',
                'role' => 'primary',
                'environment' => 'production',
                'notes' => '',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('DNS original', $server->fresh()->name);
    }

    public function test_admin_can_disable_and_enable_server(): void
    {
        [$admin, $organization] = $this->admin();

        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'enabled' => true,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patch(route('servers.status', $server))
            ->assertRedirect(route('servers.index'));

        $server->refresh();

        $this->assertFalse($server->enabled);
        $this->assertSame('maintenance', $server->status);

        $this->actingAs($admin)
            ->patch(route('servers.status', $server))
            ->assertRedirect(route('servers.index'));

        $server->refresh();

        $this->assertTrue($server->enabled);
        $this->assertSame('pending', $server->status);
    }

    public function test_admin_cannot_update_server_from_other_organization(): void
    {
        [$admin] = $this->admin();

        $otherOrganization = Organization::factory()->create();

        $server = DnsServer::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $this->actingAs($admin)
            ->put(route('servers.update', $server), [
                'name' => 'Tentativa indevida',
                'hostname' => 'ns1.bloqueado.test',
                'ipv4_address' => '192.0.2.70',
                'role' => 'primary',
                'environment' => 'production',
            ])
            ->assertNotFound();
    }

    public function test_operator_cannot_create_server(): void
    {
        [$operator] = $this->member('operator');

        $this->actingAs($operator)
            ->post(route('servers.store'), [
                'name' => 'DNS não autorizado',
                'hostname' => 'ns1.nao-autorizado.test',
                'ipv4_address' => '192.0.2.80',
                'role' => 'primary',
                'environment' => 'production',
            ])
            ->assertForbidden();
    }

    public function test_hostname_with_multiple_trailing_dots_is_rejected(): void
    {
        [$user] = $this->admin();

        $response = $this
            ->actingAs($user)
            ->from(route('servers.index'))
            ->post(route('servers.store'), [
                'name' => 'DNS inválido',
                'hostname' => 'ns1.exemplo.com.br...',
                'ipv4_address' => '192.0.2.54',
                'role' => 'primary',
                'environment' => 'production',
            ]);

        $response
            ->assertRedirect(route('servers.index'))
            ->assertSessionHasErrors('hostname');

        $this->assertDatabaseMissing('dns_servers', [
            'hostname' => 'ns1.exemplo.com.br',
        ]);
    }

    private function admin(): array
    {
        return $this->member('organization_admin');
    }

    private function member(string $role): array
    {
        $organization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->organizations()->attach(
            $organization->id,
            [
                'role' => $role,
                'status' => 'active',
                'is_default' => true,
            ],
        );

        return [$user, $organization];
    }
}
