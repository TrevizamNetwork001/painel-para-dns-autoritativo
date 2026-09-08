<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindApplyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_server_is_locked_while_checking_for_an_existing_application(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);

        config(['database.connections.contender' => config('database.connections.pgsql')]);
        $contender = DB::connection('contender');
        $serverId = $context['server']->id;
        $probe = fn () => $contender->transaction(fn () => $contender->select(
            'SELECT id FROM dns_servers WHERE id = ? FOR UPDATE NOWAIT',
            [$serverId],
        ));

        // Fixtures must be committed so that only the controller's lock
        // prevents another connection from acquiring this server.
        $this->assertCount(1, $probe());
        $checked = false;

        DB::listen(function (QueryExecuted $query) use ($probe, &$checked): void {
            if ($checked || $query->connectionName !== 'pgsql'
                || ! str_starts_with($query->sql, 'select exists(')
                || ! str_contains($query->sql, 'dns_bind_operations')) {
                return;
            }

            $checked = true;

            try {
                $probe();
                $this->fail('Another connection acquired the server during authorization.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) $exception->getCode());
            }
        });

        try {
            $this->actingAs($context['admin'])
                ->postJson(route('servers.bind.apply', $context['server']))
                ->assertOk();

            $this->assertTrue($checked);
            $this->assertCount(1, $probe());

            $this->postJson(route('servers.bind.apply', $context['server']))
                ->assertStatus(409);
            $this->assertDatabaseCount('dns_bind_operations', 1);
        } finally {
            DB::purge('contender');
        }
    }

    public function test_admin_can_trigger_apply_when_something_is_pending(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'authorized');

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'apply_zones',
            'status' => 'authorized',
        ]);
    }

    public function test_viewer_cannot_trigger_apply(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);
        $viewer = $this->member($context['organization'], 'viewer');

        $this->actingAs($viewer)
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertForbidden();
    }

    public function test_other_tenant_cannot_trigger_apply(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);
        $foreign = $this->context('Outra Organização');

        $this->actingAs($foreign['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertNotFound();
    }

    public function test_apply_is_rejected_when_nothing_is_pending(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertStatus(409);

        $this->assertDatabaseMissing('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'apply_zones',
        ]);
    }

    public function test_apply_is_rejected_when_already_in_flight(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertOk();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertStatus(409);

        $this->assertDatabaseCount('dns_bind_operations', 1);
    }

    public function test_status_reports_latest_apply_operation(): void
    {
        $context = $this->context();
        $this->pendingPublication($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.apply', $context['server']))
            ->assertOk();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.apply.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'authorized');
    }

    private function pendingPublication(array $context): DnsAgentPublication
    {
        $zone = DnsZone::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'example.com',
            'kind' => 'primary',
            'serial' => 2026090801,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.example.com',
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 1,
            'enabled' => true,
        ]);

        $version = DnsZoneVersion::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $zone->id,
            'version' => 1,
            'serial' => 2026090801,
            'reason' => 'Zona publicada.',
            'snapshot' => [],
        ]);

        return DnsAgentPublication::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_version_id' => $version->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'status' => 'pending',
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

        $token = Str::random(96);
        $agent = DnsAgent::query()->create([
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

        return compact('organization', 'admin', 'server', 'agent', 'token');
    }
}
