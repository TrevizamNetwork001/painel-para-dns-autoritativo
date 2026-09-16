<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAuditLog;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_create_update_and_delete_are_logged(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $zone), [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 300,
                'content' => '192.0.2.40',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'zone.record.created',
            'domain' => $zone->name,
            'record_type' => 'A',
            'record_name' => 'www',
            'new_value' => '192.0.2.40',
        ]);

        $record = $zone->records()->where('name', 'www')->firstOrFail();

        $this->actingAs($context['admin'])
            ->put(route('zones.records.update', [$zone, $record]), [
                'name' => 'web',
                'type' => 'AAAA',
                'ttl' => 600,
                'content' => '2001:db8::40',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'zone.record.updated',
            'domain' => $zone->name,
            'record_type' => 'AAAA',
            'record_name' => 'web',
            'new_value' => 'web AAAA → 2001:db8::40',
        ]);

        $this->actingAs($context['admin'])
            ->delete(route('zones.records.destroy', [$zone, $record]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'zone.record.deleted',
            'domain' => $zone->name,
            'record_type' => 'AAAA',
            'record_name' => 'web',
        ]);
    }

    public function test_zone_publish_is_logged(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'zone.published',
            'domain' => $zone->name,
        ]);
    }

    public function test_server_actions_are_logged(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('servers.store'), [
                'name' => 'ns3 teste',
                'hostname' => 'ns3.teste.example',
                'ipv4_address' => '192.0.2.99',
                'role' => 'secondary',
                'environment' => 'production',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'server.created',
            'record_name' => 'ns3 teste',
        ]);

        $server = DnsServer::query()
            ->where('hostname', 'ns3.teste.example')
            ->firstOrFail();

        $this->actingAs($context['admin'])
            ->patch(route('servers.status', $server))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'server.status_changed',
            'record_name' => 'ns3 teste',
        ]);
    }

    public function test_user_management_actions_are_logged(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('users.store'), [
                'name' => 'Nova Pessoa',
                'email' => 'nova@example.com',
                'password' => 'SenhaForte@2026',
                'password_confirmation' => 'SenhaForte@2026',
                'role' => 'viewer',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'user.created',
            'record_name' => 'nova@example.com',
            'new_value' => 'viewer',
        ]);

        $newUser = User::query()->where('email', 'nova@example.com')->firstOrFail();

        $this->actingAs($context['admin'])
            ->patch(route('users.role', $newUser), ['role' => 'operator'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'user.role_updated',
            'record_name' => 'nova@example.com',
            'old_value' => 'viewer',
            'new_value' => 'operator',
        ]);

        $this->actingAs($context['admin'])
            ->post(route('users.status', $newUser))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'action' => 'user.status_updated',
            'record_name' => 'nova@example.com',
        ]);
    }

    public function test_login_success_and_failure_are_logged(): void
    {
        $context = $this->context();
        $user = $context['admin'];

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'senha-errada',
        ]);

        $this->assertDatabaseHas('dns_audit_logs', [
            'action' => 'auth.login_failed',
            'status' => 'error',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'SenhaForte@2026',
        ])->assertRedirect();

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'user_id' => $user->id,
            'action' => 'auth.login_succeeded',
        ]);

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseHas('dns_audit_logs', [
            'organization_id' => $context['organization']->id,
            'user_id' => $user->id,
            'action' => 'auth.logout',
        ]);
    }

    public function test_only_organization_admin_or_platform_admin_can_view_audit_page(): void
    {
        $context = $this->context();

        foreach (['operator', 'viewer'] as $role) {
            $member = $this->member($context['organization'], $role);

            $this->actingAs($member)
                ->get(route('audit.index'))
                ->assertForbidden();
        }

        $this->actingAs($context['admin'])
            ->get(route('audit.index'))
            ->assertOk();
    }

    public function test_audit_log_is_scoped_to_organization(): void
    {
        $contextA = $this->context('Empresa A');
        $contextB = $this->context('Empresa B');

        $zoneA = $this->createZone($contextA, 'a-example.com');
        $this->createZone($contextB, 'b-example.com');

        $response = $this->actingAs($contextB['admin'])
            ->get(route('audit.index'));

        $response->assertOk();
        $response->assertDontSee($zoneA->name);
    }

    public function test_filters_narrow_results(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $zone), [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 300,
                'content' => '192.0.2.40',
            ]);

        $response = $this->actingAs($context['admin'])
            ->get(route('audit.index', ['action' => 'zone.record.created']));

        $response->assertOk();
        $response->assertSee(DnsAuditLog::actionLabel('zone.record.created'));

        $response = $this->actingAs($context['admin'])
            ->get(route('audit.index', ['domain' => 'nao-existe.example']));

        $response->assertOk();
        $response->assertSee('Nenhum registro encontrado.');
    }

    public function test_csv_export_returns_expected_rows(): void
    {
        $context = $this->context();
        $this->createZone($context);

        $response = $this->actingAs($context['admin'])
            ->get(route('audit.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Data;Usuário;Ação;Domínio', $csv);
        $this->assertStringContainsString(
            DnsAuditLog::actionLabel('zone.created'),
            $csv,
        );
    }

    private function createZone(array $context, string $name = 'example.com'): DnsZone
    {
        $this->actingAs($context['admin'])
            ->post(route('zones.store'), $this->zonePayload($context, ['name' => $name]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', $name)
            ->firstOrFail();

        $zone->forceFill(['dns_tsig_key_id' => $context['tsigKey']->id])->save();

        return $zone;
    }

    private function zonePayload(array $context, array $overrides = []): array
    {
        return array_merge([
            'name' => 'example.com',
            'kind' => 'primary',
            'dns_nameserver_profile_id' => $context['profile']->id,
            'default_ttl' => 3600,
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'primary_server_id' => $context['primary']->id,
            'secondary_server_id' => $context['secondary']->id,
        ], $overrides);
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

    private function context(
        string $name = 'Empresa Teste',
        bool $withAgents = false,
    ): array {
        $organization = Organization::factory()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = User::factory()->create([
            'email' => Str::slug($name).'-admin@example.com',
            'password' => Hash::make('SenhaForte@2026'),
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $primary = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name.' principal',
            'hostname' => 'dns1.'.Str::slug($name).'.example',
            'role' => 'primary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        $secondary = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name.' secundário',
            'hostname' => 'dns2.'.Str::slug($name).'.example',
            'role' => 'secondary',
            'status' => 'online',
            'agent_status' => 'online',
        ]);

        if ($withAgents) {
            $this->createAgent($organization, $primary);
            $this->createAgent($organization, $secondary);
        }

        $firstIdentity = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $primary->id,
            'name' => 'NS principal',
            'hostname' => 'ns1.provider.example',
            'ipv4_address' => '192.0.2.10',
            'enabled' => true,
        ]);

        $secondIdentity = DnsNameserverIdentity::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $secondary->id,
            'name' => 'NS secundário',
            'hostname' => 'ns2.provider.example',
            'ipv4_address' => '192.0.2.20',
            'enabled' => true,
        ]);

        $profile = DnsNameserverProfile::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Produção',
            'is_default' => true,
            'enabled' => true,
        ]);

        $profile->identities()->sync([
            $firstIdentity->id => ['position' => 1],
            $secondIdentity->id => ['position' => 2],
        ]);

        $tsigKey = DnsTsigKey::query()->create([
            'organization_id' => $organization->id,
            'name' => 'xfr-'.Str::lower(Str::random(12)),
            'algorithm' => 'hmac-sha256',
            'secret' => base64_encode(random_bytes(32)),
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        return compact('organization', 'admin', 'primary', 'secondary', 'profile', 'tsigKey');
    }

    private function createAgent(
        Organization $organization,
        DnsServer $server,
    ): string {
        $token = Str::random(96);

        DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => Str::random(64),
            'token_hash' => hash('sha256', $token),
            'reported_hostname' => $server->hostname,
            'registered_ip' => '127.0.0.1',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [],
        ]);

        return $token;
    }
}
