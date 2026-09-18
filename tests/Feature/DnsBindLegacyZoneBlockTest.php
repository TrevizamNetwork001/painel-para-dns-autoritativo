<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindLegacyZoneBlockTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_can_authorize_removal_of_a_reported_conflict(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'authorized');

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'remove_legacy_zone_block',
            'status' => 'authorized',
        ]);

        $operation = DnsBindOperation::query()->firstOrFail();
        $this->assertSame('example.com', $operation->params['zone_name']);
        $this->assertSame('/etc/bind/named.conf.local', $operation->params['source_file']);
    }

    public function test_removal_is_rejected_when_block_no_longer_matches_known_readiness(): void
    {
        $context = $this->context();
        // Zone managed, but no bind_readiness stored at all — nothing to confirm against.
        $zone = DnsZone::query()->create($this->zoneAttributes($context['organization']));
        $zone->servers()->sync([$context['server']->id => ['role' => 'primary']]);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertStatus(409);

        $this->assertDatabaseMissing('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'remove_legacy_zone_block',
        ]);
    }

    public function test_removal_is_rejected_when_zone_is_not_managed_by_this_server(): void
    {
        $context = $this->context();
        // Readiness reports the block, but the zone isn't attached to this server.
        $context['server']->forceFill([
            'bind_readiness' => [
                'legacy_zone_blocks' => [
                    'blocks' => [
                        [
                            'name' => 'example.com',
                            'source_file' => '/etc/bind/named.conf.local',
                            'start_line' => 2,
                            'end_line' => 6,
                            'declared_type' => 'master',
                            'hash' => str_repeat('a', 64),
                            'snippet' => null,
                        ],
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertStatus(409);
    }

    public function test_removal_is_rejected_when_already_in_flight(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk();

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertStatus(409);

        $this->assertDatabaseCount('dns_bind_operations', 1);
    }

    public function test_viewer_cannot_authorize_removal(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);
        $viewer = $this->member($context['organization'], 'viewer');

        $this->actingAs($viewer)
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertForbidden();
    }

    public function test_status_reports_latest_removal_operation(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk();

        $operation = DnsBindOperation::query()->firstOrFail();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.legacy-block.status', [$context['server'], $operation]))
            ->assertOk()
            ->assertJsonPath('status', 'authorized');
    }

    public function test_status_reports_whether_readiness_was_refreshed_after_the_operation(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk();

        $operation = DnsBindOperation::query()->firstOrFail();
        $operation->forceFill(['status' => 'succeeded', 'completed_at' => now()])->save();

        $context['server']->forceFill(['bind_readiness_at' => now()->subMinute()])->save();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.legacy-block.status', [$context['server'], $operation]))
            ->assertOk()
            ->assertJsonPath('readiness_refreshed', false);

        $context['server']->forceFill(['bind_readiness_at' => now()->addSecond()])->save();

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.legacy-block.status', [$context['server'], $operation]))
            ->assertOk()
            ->assertJsonPath('readiness_refreshed', true);
    }

    public function test_next_operation_endpoint_hands_params_to_the_agent(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk();

        $this->withToken($context['token'])
            ->getJson('/api/agent/bind/operations/next')
            ->assertOk()
            ->assertJsonPath('operation.action', 'remove_legacy_zone_block')
            ->assertJsonPath('operation.params.source_file', '/etc/bind/named.conf.local')
            ->assertJsonPath('operation.params.start_line', 2)
            ->assertJsonPath('operation.params.hash', str_repeat('a', 64));
    }

    public function test_successful_report_preserves_result_fields_including_paths(): void
    {
        // Real bug caught live in production: sanitizeResult()'s whitelist
        // never included this action's result keys, so a successful removal
        // was silently stored as an empty result — the operation showed
        // "succeeded" but nothing about what was actually removed.
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $this->actingAs($context['admin'])
            ->postJson(route('servers.bind.legacy-block.remove', $context['server']), $this->blockPayload())
            ->assertOk();

        $operation = DnsBindOperation::query()->firstOrFail();

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ],
        )->assertOk();

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'succeeded',
                'result' => [
                    'removed' => true,
                    'zone_name' => 'example.com',
                    'source_file' => '/etc/bind/named.conf.local',
                    'start_line' => 2,
                    'end_line' => 6,
                    'backup_dir' => '/var/backups/dns-center-agent/20260917-legacy-removal',
                    'zonefile_preserved' => false,
                    'unexpected_key' => 'deve ser descartada',
                ],
            ],
        )->assertOk();

        $result = $operation->fresh()->result;
        $this->assertTrue($result['removed']);
        $this->assertSame('example.com', $result['zone_name']);
        $this->assertSame('/etc/bind/named.conf.local', $result['source_file']);
        $this->assertSame(2, $result['start_line']);
        $this->assertSame(
            '/var/backups/dns-center-agent/20260917-legacy-removal',
            $result['backup_dir'],
        );
        $this->assertFalse($result['zonefile_preserved']);
        $this->assertArrayNotHasKey('unexpected_key', $result);
    }

    public function test_zone_page_shows_conflict_panel_and_combined_action_data(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);
        $zone = DnsZone::query()->where('name', 'example.com')->sole();

        $response = $this->actingAs($context['admin'])
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('Declaração de zona legada bloqueia a publicação')
            ->assertSee($context['server']->name)
            ->assertSee('/etc/bind/named.conf.local:2')
            ->assertSee('Remover declarações antigas e publicar');

        $response->assertSee(route('servers.bind.legacy-block.remove', $context['server']), false);
    }

    public function test_combined_publish_script_waits_for_readiness_refresh(): void
    {
        // Sem isso, a publicação é enviada logo após a remoção e o validador ainda
        // enxerga o conflito no relatório de prontidão antigo.
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);
        $zone = DnsZone::query()->where('name', 'example.com')->sole();

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('payload.readiness_refreshed === true', false);
    }

    public function test_zone_page_shows_normal_publish_actions_without_conflict(): void
    {
        $context = $this->context();
        $zone = DnsZone::query()->create($this->zoneAttributes($context['organization']));
        $zone->servers()->sync([$context['server']->id => ['role' => 'primary']]);

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('Publicar e sincronizar')
            ->assertSee('Só publicar')
            ->assertDontSee('Declaração de zona legada bloqueia a publicação');
    }

    public function test_conflict_on_a_different_zone_does_not_leak_into_this_zone_page(): void
    {
        $context = $this->context();
        $this->conflictingZoneAndReadiness($context);

        $otherZone = DnsZone::query()->create(array_merge(
            $this->zoneAttributes($context['organization']),
            ['name' => 'outra-zona.example'],
        ));
        $otherZone->servers()->sync([$context['server']->id => ['role' => 'primary']]);

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $otherZone))
            ->assertOk()
            ->assertDontSee('Declaração de zona legada bloqueia a publicação')
            ->assertSee('Publicar e sincronizar');
    }

    public function test_agent_page_shows_reverse_conflict_as_cidr_block_with_progress_bar(): void
    {
        $context = $this->context();
        $zone = DnsZone::query()->create(array_merge(
            $this->zoneAttributes($context['organization']),
            ['name' => '113.0.203.in-addr.arpa'],
        ));
        $zone->servers()->sync([$context['server']->id => ['role' => 'primary']]);

        $context['server']->forceFill([
            'bind_readiness' => [
                'legacy_zone_blocks' => [
                    'blocks' => [[
                        'name' => '113.0.203.in-addr.arpa',
                        'source_file' => '/etc/bind/named.conf.local',
                        'start_line' => 6,
                        'end_line' => 9,
                        'declared_type' => 'master',
                        'hash' => str_repeat('b', 64),
                        'snippet' => 'zone "113.0.203.in-addr.arpa" { type master; };',
                    ]],
                ],
            ],
        ])->save();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('203.0.113.0/24')
            ->assertSee('data-legacy-block-cidr="203.0.113.0/24"', false)
            ->assertSee('data-legacy-block-progress-track', false)
            ->assertDontSee('até ~30s');
    }

    private function blockPayload(): array
    {
        return [
            'source_file' => '/etc/bind/named.conf.local',
            'start_line' => 2,
            'end_line' => 6,
            'hash' => str_repeat('a', 64),
            'zone_name' => 'example.com',
        ];
    }

    private function conflictingZoneAndReadiness(array $context): void
    {
        $zone = DnsZone::query()->create($this->zoneAttributes($context['organization']));
        $zone->servers()->sync([$context['server']->id => ['role' => 'primary']]);

        $context['server']->forceFill([
            'bind_readiness' => [
                'legacy_zone_blocks' => [
                    'blocks' => [
                        [
                            'name' => 'example.com',
                            'source_file' => '/etc/bind/named.conf.local',
                            'start_line' => 2,
                            'end_line' => 6,
                            'declared_type' => 'master',
                            'hash' => str_repeat('a', 64),
                            'snippet' => 'zone "example.com" { type master; };',
                        ],
                    ],
                ],
            ],
        ])->save();
    }

    private function zoneAttributes(Organization $organization): array
    {
        return [
            'organization_id' => $organization->id,
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
        ];
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
