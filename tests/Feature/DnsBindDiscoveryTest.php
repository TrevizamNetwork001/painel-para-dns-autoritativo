<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsBindDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_trigger_discovery_and_it_is_admin_only(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discover', $context['server']))
            ->assertRedirect(route('servers.agent.show', $context['server']));

        $this->assertDatabaseHas('dns_bind_operations', [
            'dns_server_id' => $context['server']->id,
            'action' => 'discover_bind_zones',
            'status' => 'authorized',
        ]);

        $viewer = $this->member($context['organization'], 'viewer');
        $this->actingAs($viewer)
            ->post(route('servers.bind.discover', $context['server']))
            ->assertForbidden();
    }

    public function test_other_tenant_cannot_access_server_discovery(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra Organização');

        $this->actingAs($foreign['admin'])
            ->post(route('servers.bind.discover', $context['server']))
            ->assertNotFound();

        $this->actingAs($foreign['admin'])
            ->get(route('servers.bind.discovery.show', $context['server']))
            ->assertNotFound();
    }

    public function test_report_ingests_zones_and_computes_comparison_states(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);

        $existing = DnsZone::query()->create($this->zoneAttributes($context, 'existing.example.com', [
            'serial' => 2026082701,
        ]));

        $this->markOperationRunning($operation, $context['token']);

        $response = $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            $this->reportDiscoveryPayload($operation, 'succeeded', [
                $this->sampleZone(['name' => 'new.example.com']),
                $this->sampleZone(['name' => 'existing.example.com', 'serial' => 2026082701, 'records' => [
                    ['name' => 'existing.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.example.com.'],
                ]]),
                $this->sampleZone(['name' => 'secondary.example.com', 'detected_type' => 'secondary', 'records' => null]),
                $this->sampleZone(['name' => 'unsupported.example.com', 'unsupported_record_types' => ['SRV']]),
            ]),
        );

        $response->assertOk();

        $states = DnsBindDiscoveredZone::query()
            ->where('dns_bind_operation_id', $operation->id)
            ->pluck('comparison_state', 'name');

        $this->assertSame('new', $states['new.example.com']);
        $this->assertSame('secondary_external', $states['secondary.example.com']);
        $this->assertSame('not_supported', $states['unsupported.example.com']);
        $this->assertContains($states['existing.example.com'], ['exists', 'conflict']);
    }

    public function test_repeated_report_with_same_event_is_idempotent(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);
        $payload = $this->reportDiscoveryPayload($operation, 'succeeded', [
            $this->sampleZone(['name' => 'idempotent.example.com']),
        ]);

        $this->markOperationRunning($operation, $context['token']);

        $this->withToken($context['token'])
            ->postJson(route('api.agent.bind.operations.report', $operation), $payload)
            ->assertOk()->assertJsonPath('idempotent', false);

        $this->withToken($context['token'])
            ->postJson(route('api.agent.bind.operations.report', $operation), $payload)
            ->assertOk()->assertJsonPath('idempotent', true);

        $this->assertDatabaseCount('dns_bind_discovered_zones', 1);
    }

    public function test_agent_page_shows_factual_discovery_and_import_stages(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);
        $this->markOperationRunning($operation, $context['token']);

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            $this->reportDiscoveryPayload($operation, 'succeeded', [
                $this->sampleZone(['name' => 'primary.example.com']),
                $this->sampleZone([
                    'name' => 'secondary.example.com',
                    'detected_type' => 'secondary',
                    'records' => null,
                ]),
            ]),
        )->assertOk();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('2 zonas')
            ->assertSee('Primary')
            ->assertSee('Secondary')
            ->assertSee('Descoberto')
            ->assertSee('Concluído')
            ->assertSee('Importado')
            ->assertSee('Não iniciado')
            ->assertSee('Gerenciado')
            ->assertSee('Alterações externas')
            ->assertSee('Não verificado');
    }

    public function test_import_creates_zone_and_records_preserving_serial_and_ttl(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'imported.example.com',
            'serial' => 2026082701,
            'records' => [
                ['name' => 'imported.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.imported.example.com.'],
                ['name' => 'www', 'ttl' => 1800, 'type' => 'A', 'content' => '198.51.100.5'],
            ],
            'comparison_state' => 'new',
        ]);

        $this->actingAs($context['admin'])
            ->post(route('servers.bind.discovery.import', $context['server']), [
                'zone_ids' => [$discovered->id],
            ])
            ->assertRedirect();

        $zone = DnsZone::query()->where('name', 'imported.example.com')->firstOrFail();
        $this->assertSame('bind_import', $zone->origin);
        $this->assertSame(2026082701, $zone->serial);
        $this->assertSame('draft', $zone->status);
        $this->assertSame($discovered->id, $zone->import_source_id);
        $this->assertNotNull($zone->imported_at);

        $record = $zone->records()->where('name', 'www')->firstOrFail();
        $this->assertSame(1800, $record->ttl);
        $this->assertSame('198.51.100.5', $record->content);
    }

    public function test_imported_zone_blocks_publish(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'blocked.example.com',
            'records' => [
                ['name' => 'blocked.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.blocked.example.com.'],
            ],
            'comparison_state' => 'new',
        ]);

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$discovered->id],
        ]);

        $zone = DnsZone::query()->where('name', 'blocked.example.com')->firstOrFail();

        $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertStatus(409);

        $zone->refresh();
        $this->assertSame('draft', $zone->status);
    }

    public function test_import_never_creates_agent_publication(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'nopublish.example.com',
            'records' => [
                ['name' => 'nopublish.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.example.com.'],
            ],
            'comparison_state' => 'new',
        ]);

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$discovered->id],
        ]);

        $this->assertDatabaseCount('dns_agent_publications', 0);
    }

    public function test_secondary_zone_is_never_importable(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'secondary-only.example.com',
            'detected_type' => 'secondary',
            'records' => null,
            'comparison_state' => 'secondary_external',
        ]);

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$discovered->id],
        ]);

        $this->assertDatabaseMissing('dns_zones', ['name' => 'secondary-only.example.com']);
        $this->assertDatabaseHas('security_audits', ['event' => 'dns.bind_zone_import_skipped']);
    }

    public function test_unsupported_record_types_block_import(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'hassrv.example.com',
            'unsupported_record_types' => ['SRV'],
            'records' => [
                ['name' => 'hassrv.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.example.com.'],
            ],
            'comparison_state' => 'not_supported',
        ]);

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$discovered->id],
        ]);

        $this->assertDatabaseMissing('dns_zones', ['name' => 'hassrv.example.com']);
    }

    public function test_batch_import_partial_failure_does_not_corrupt_other_zones(): void
    {
        $context = $this->context();
        $good = $this->discoveredZone($context, [
            'name' => 'good.example.com',
            'records' => [
                ['name' => 'good.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.example.com.'],
            ],
            'comparison_state' => 'new',
        ]);
        $conflicting = $this->discoveredZone($context, [
            'name' => 'good.example.com',
            'records' => [
                ['name' => 'good.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.example.com.'],
            ],
            'comparison_state' => 'new',
        ]);
        // Force a duplicate-name conflict at import time by pre-creating the zone
        // for the second discovered row, simulating a race/already-imported case.
        DnsZone::query()->create($this->zoneAttributes($context, 'good.example.com'));

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$good->id, $conflicting->id],
        ]);

        $this->assertDatabaseCount('dns_zones', 1);
    }

    public function test_large_zone_imports_correctly(): void
    {
        $context = $this->context();
        $records = [];
        for ($index = 0; $index < 1030; $index++) {
            $records[] = [
                'name' => "host{$index}",
                'ttl' => 3600,
                'type' => 'A',
                'content' => '10.0.'.intdiv($index, 256).'.'.($index % 256),
            ];
        }

        $discovered = $this->discoveredZone($context, [
            'name' => 'big.example.com',
            'node_count' => 1030,
            'records' => $records,
            'comparison_state' => 'new',
        ]);

        $this->actingAs($context['admin'])->post(route('servers.bind.discovery.import', $context['server']), [
            'zone_ids' => [$discovered->id],
        ]);

        $zone = DnsZone::query()->where('name', 'big.example.com')->firstOrFail();
        $this->assertSame(1030, $zone->records()->count());
    }

    public function test_audit_never_contains_zone_content_or_secret_markers(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);
        $this->markOperationRunning($operation, $context['token']);

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            $this->reportDiscoveryPayload($operation, 'succeeded', [
                $this->sampleZone(['name' => 'audited.example.com']),
            ]),
        )->assertOk();

        $reasons = SecurityAudit::query()->pluck('reason')->filter()->implode('|');
        $this->assertStringNotContainsString('secret', strtolower($reasons));
        $this->assertStringNotContainsString('198.51.100.10', $reasons);
    }

    public function test_agent_page_renders_compact_async_discovery_states_without_fake_percentage(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Descoberta do BIND')
            ->assertSee('Aguardando execução do agente')
            ->assertSee('Solicitação')
            ->assertSee('Execução')
            ->assertSee('Resultado')
            ->assertSee('Tempo decorrido')
            ->assertSee('Continuar em segundo plano')
            ->assertSee('Somente leitura')
            ->assertSee('Acompanhar descoberta')
            ->assertSee('data-discovery-active="true"', false)
            ->assertDontSee('data-discovery-percent', false);

        $operation->update(['status' => 'running']);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee("payload.status === 'running'", false)
            ->assertSee("status.textContent = 'Executando descoberta'", false);
    }

    public function test_discovery_status_exposes_factual_timing_and_agent_connectivity(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);

        $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.discovery.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'authorized')
            ->assertJsonPath('agent_online', true)
            ->assertJsonPath('requested_at', $operation->authorized_at->toIso8601String())
            ->assertJsonPath('agent_last_seen_at', $context['agent']->last_seen_at->toIso8601String());
    }

    public function test_succeeded_status_limits_modal_preview_and_keeps_compact_summary(): void
    {
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);
        $operation->update(['status' => 'succeeded', 'completed_at' => now()]);

        foreach (range(1, 8) as $index) {
            DnsBindDiscoveredZone::query()->create([
                'organization_id' => $context['organization']->id,
                'dns_server_id' => $context['server']->id,
                'dns_agent_id' => $context['agent']->id,
                'dns_bind_operation_id' => $operation->id,
                'name' => "zone-{$index}.example.com",
                'detected_type' => 'primary',
                'detected_syntax' => 'master',
                'validation_status' => 'ok',
                'comparison_state' => 'new',
            ]);
        }

        $response = $this->actingAs($context['admin'])
            ->getJson(route('servers.bind.discovery.status', $context['server']))
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('summary.total', 8)
            ->assertJsonPath('summary.primary', 8)
            ->assertJsonPath('summary.new', 8);

        $this->assertCount(6, $response->json('summary.zones'));
    }

    public function test_failed_state_uses_sanitized_modal_copy_and_cross_tenant_status_is_hidden(): void
    {
        $context = $this->context();
        $foreign = $this->context('Tenant estrangeiro');
        $operation = $this->authorizedDiscoveryOperation($context);
        $operation->update(['status' => 'failed', 'error' => 'stack trace: segredo interno']);

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('O agente retornou uma falha durante a descoberta.')
            ->assertDontSee('stack trace: segredo interno');

        $this->actingAs($foreign['admin'])
            ->getJson(route('servers.bind.discovery.status', $context['server']))
            ->assertNotFound();
    }

    private function markOperationRunning(DnsBindOperation $operation, string $token): void
    {
        $this->withToken($token)->postJson(
            route('api.agent.bind.operations.report', $operation),
            [
                'event_id' => (string) Str::uuid(),
                'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
                'status' => 'running',
            ],
        )->assertOk();
    }

    public function test_zone_preview_shows_record_content_not_dash(): void
    {
        // Golden regression for the real c.b.2.5.4.0.8.2.ip6.arpa case: the
        // preview page was reading $record['rdata'] while the persisted/
        // sanitized shape uses 'content', so every NS/PTR rendered as "—"
        // even though the RDATA was correctly stored in the database.
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'c.b.2.5.4.0.8.2.ip6.arpa',
            'node_count' => 4,
            'soa' => [
                'mname' => 'ns1.conectanetwork.net.br.',
                'rname' => 'hostmaster.conectanetwork.net.br.',
                'serial' => 2026082701,
                'refresh' => 900,
                'retry' => 3600,
                'expire' => 2419200,
                'minimum' => 300,
            ],
            'records' => [
                ['name' => 'c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.conectanetwork.net.br.'],
                ['name' => 'c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns2.conectanetwork.net.br.'],
                ['name' => '2.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'ns1.conectanetwork.net.br.'],
                ['name' => '3.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'ns2.conectanetwork.net.br.'],
                ['name' => '0.5.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'www.conectanetwork.net.br.'],
            ],
            'comparison_state' => 'new',
        ]);

        $response = $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.zone', [$context['server'], $discovered]));

        $response->assertOk();
        $response->assertSee('ns1.conectanetwork.net.br.');
        $response->assertSee('ns2.conectanetwork.net.br.');
        $response->assertSee('www.conectanetwork.net.br.');
        // The 5 real RDATA values must render — none of the 5 rows may fall
        // back to the placeholder used only for genuinely missing content.
        $response->assertSeeInOrder(['Nome', 'TTL', 'Tipo', 'Conteúdo']);
    }

    public function test_zone_preview_distinguishes_bind_nodes_from_parsed_record_count(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'c.b.2.5.4.0.8.2.ip6.arpa',
            'node_count' => 4,
            'records' => [
                ['name' => 'c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.conectanetwork.net.br.'],
                ['name' => 'c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns2.conectanetwork.net.br.'],
                ['name' => '2.4.2....c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'ns1.conectanetwork.net.br.'],
                ['name' => '3.4.2....c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'ns2.conectanetwork.net.br.'],
                ['name' => '0.5.2....c.b.2.5.4.0.8.2.ip6.arpa.', 'ttl' => 3600, 'type' => 'PTR', 'content' => 'www.conectanetwork.net.br.'],
            ],
            'comparison_state' => 'new',
        ]);

        $response = $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.zone', [$context['server'], $discovered]));

        $response->assertOk();
        // node_count (4, from rndc) and parsed record count (5 RRs) are
        // different metrics and must both be visible, never collapsed
        // into a single ambiguous "Registros" number.
        $response->assertSee('Nodes (BIND, via rndc)');
        $response->assertSee('Registros parseados');
        $response->assertSee('Página 1 · 5 no total');
    }

    public function test_zone_preview_shows_soa_in_its_own_section(): void
    {
        $context = $this->context();
        $discovered = $this->discoveredZone($context, [
            'name' => 'soa-visible.example.com',
            'soa' => [
                'mname' => 'ns1.soa-visible.example.com.',
                'rname' => 'hostmaster.soa-visible.example.com.',
                'serial' => 2026082701,
                'refresh' => 3600,
                'retry' => 900,
                'expire' => 1209600,
                'minimum' => 300,
            ],
            'records' => [
                ['name' => 'soa-visible.example.com.', 'ttl' => 3600, 'type' => 'NS', 'content' => 'ns1.soa-visible.example.com.'],
            ],
            'comparison_state' => 'new',
        ]);

        $response = $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.zone', [$context['server'], $discovered]));

        $response->assertOk();
        $response->assertSee('Start of Authority');
        $response->assertSee('ns1.soa-visible.example.com.');
        $response->assertSee('hostmaster.soa-visible.example.com.');
        $response->assertSee('2026082701');
    }

    public function test_full_round_trip_from_agent_report_to_preview_preserves_rdata(): void
    {
        // End-to-end regression: agent payload (rdata key) -> report()
        // sanitization (renames to content) -> persistence -> preview page.
        // Exercises the exact boundary where the real bug happened.
        $context = $this->context();
        $operation = $this->authorizedDiscoveryOperation($context);
        $this->markOperationRunning($operation, $context['token']);

        $this->withToken($context['token'])->postJson(
            route('api.agent.bind.operations.report', $operation),
            $this->reportDiscoveryPayload($operation, 'succeeded', [
                $this->sampleZone([
                    'name' => 'roundtrip.example.com',
                    'records' => [
                        ['name' => 'roundtrip.example.com.', 'ttl' => 3600, 'type' => 'NS', 'rdata' => 'ns1.roundtrip.example.com.'],
                        ['name' => 'roundtrip.example.com.', 'ttl' => 3600, 'type' => 'NS', 'rdata' => 'ns2.roundtrip.example.com.'],
                    ],
                ]),
            ]),
        )->assertOk();

        $discovered = DnsBindDiscoveredZone::query()->where('name', 'roundtrip.example.com')->sole();

        $response = $this->actingAs($context['admin'])
            ->get(route('servers.bind.discovery.zone', [$context['server'], $discovered]));

        $response->assertOk();
        $response->assertSee('ns1.roundtrip.example.com.');
        $response->assertSee('ns2.roundtrip.example.com.');
    }

    private function reportDiscoveryPayload(DnsBindOperation $operation, string $status, ?array $zones = null): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'authorization_nonce' => $operation->getRawOriginal('authorization_nonce'),
            'status' => $status,
            'result' => $zones === null ? null : ['zones' => $zones],
        ];
    }

    private function sampleZone(array $overrides = []): array
    {
        return array_merge([
            'name' => 'example.com',
            'detected_type' => 'primary',
            'detected_syntax' => 'master',
            'file' => '/var/cache/bind/master-aut/example.com.hosts',
            'serial' => 2026082701,
            'node_count' => 2,
            'dynamic' => false,
            'secure' => false,
            'file_metadata' => [
                'owner' => 'bind',
                'group' => 'bind',
                'mode' => '0640',
                'size' => 512,
                'mtime' => '2026-08-27T00:00:00+00:00',
                'sha256' => str_repeat('a', 64),
            ],
            'validation_status' => 'ok',
            'validation_message' => null,
            'unsupported_record_types' => [],
            'soa' => [
                'mname' => 'ns1.example.com.',
                'rname' => 'hostmaster.example.com.',
                'serial' => 2026082701,
                'refresh' => 3600,
                'retry' => 900,
                'expire' => 1209600,
                'minimum' => 300,
            ],
            'records' => [
                ['name' => 'example.com.', 'ttl' => 3600, 'type' => 'NS', 'rdata' => 'ns1.example.com.'],
                ['name' => 'www', 'ttl' => 3600, 'type' => 'A', 'rdata' => '198.51.100.10'],
            ],
            'warnings' => [],
        ], $overrides);
    }

    private function authorizedDiscoveryOperation(array $context): DnsBindOperation
    {
        return DnsBindOperation::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'action' => 'discover_bind_zones',
            'status' => 'authorized',
            'authorization_nonce' => (string) Str::uuid(),
            'authorized_by' => $context['admin']->id,
            'authorized_at' => now(),
        ]);
    }

    private function discoveredZone(array $context, array $overrides): DnsBindDiscoveredZone
    {
        $operation = $this->authorizedDiscoveryOperation($context);

        return DnsBindDiscoveredZone::query()->create(array_merge([
            'organization_id' => $context['organization']->id,
            'dns_server_id' => $context['server']->id,
            'dns_agent_id' => $context['agent']->id,
            'dns_bind_operation_id' => $operation->id,
            'name' => 'example.com',
            'detected_type' => 'primary',
            'detected_syntax' => 'master',
            'file_path' => '/var/cache/bind/master-aut/example.com.hosts',
            'serial' => 2026082701,
            'node_count' => 1,
            'validation_status' => 'ok',
            'comparison_state' => 'new',
        ], $overrides));
    }

    private function zoneAttributes(array $context, string $name, array $overrides = []): array
    {
        return array_merge([
            'organization_id' => $context['organization']->id,
            'name' => $name,
            'kind' => 'primary',
            'serial' => 1,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.'.$name.'.',
            'soa_rname' => 'hostmaster.'.$name.'.',
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
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
