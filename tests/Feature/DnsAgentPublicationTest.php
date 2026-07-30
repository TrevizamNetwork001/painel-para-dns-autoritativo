<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAgentPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_exposes_real_desired_state_and_download_is_not_application(): void
    {
        $context = $this->context();

        $this->withToken($context['token'])
            ->getJson('/api/agent/zones')
            ->assertOk()
            ->assertJsonPath('zones.0.publication_id', $context['publication']->id)
            ->assertJsonPath('zones.0.desired_version', 7)
            ->assertJsonPath('zones.0.installed_version', null)
            ->assertJsonPath('zones.0.apply_status', 'pending')
            ->assertJsonPath('zones.0.update_available', true)
            ->assertJsonPath(
                'zones.0.artifact_checksum',
                hash('sha256', '$ORIGIN example.test.'.PHP_EOL),
            )
            ->assertJsonPath(
                'zones.0.artifact_size',
                strlen('$ORIGIN example.test.'.PHP_EOL),
            );

        $this->withToken($context['token'])
            ->getJson(
                '/api/agent/zones/'.$context['zone']->id.'/artifact'
                .'?publication='.$context['publication']->id,
            )
            ->assertOk()
            ->assertHeader('X-DNS-Zone-Version', '7')
            ->assertHeader(
                'X-DNS-Publication-Id',
                (string) $context['publication']->id,
            )
            ->assertHeader(
                'X-DNS-Artifact-SHA256',
                hash('sha256', '$ORIGIN example.test.'.PHP_EOL),
            )
            ->assertHeader(
                'Content-Length',
                (string) strlen('$ORIGIN example.test.'.PHP_EOL),
            )
            ->assertSee('$ORIGIN example.test.', false);

        $publication = $context['publication']->fresh();

        $this->assertSame('downloaded', $publication->status);
        $this->assertNull($publication->installed_version);
        $this->assertNotNull($publication->downloaded_at);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.artifact_downloaded',
            'result' => 'success',
        ]);
    }

    public function test_agent_reports_applying_applied_and_failed_as_real_states(): void
    {
        $context = $this->context();

        $this->report($context, 'applying')
            ->assertOk()
            ->assertJsonPath('status', 'applying');

        $this->assertDatabaseHas('dns_agent_publications', [
            'id' => $context['publication']->id,
            'status' => 'applying',
            'installed_version' => null,
        ]);

        $this->report($context, 'failed', [
            'error' => '<b>falhou</b>'.chr(1)
                .' token=segredo /etc/bind/zones/db sudo rm -rf /',
        ])->assertOk()
            ->assertJsonPath('status', 'failed');

        $failed = $context['publication']->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->installed_version);
        $this->assertStringNotContainsString('<b>', $failed->last_apply_error);
        $this->assertStringNotContainsString('segredo', $failed->last_apply_error);
        $this->assertStringNotContainsString('/etc/bind', $failed->last_apply_error);
        $this->assertStringNotContainsString('sudo rm', $failed->last_apply_error);

        $this->report($context, 'applied', [
            'installed_version' => 7,
            'artifact_checksum' => str_repeat('a', 64),
        ])->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('installed_version', 7);

        $applied = $context['publication']->fresh();
        $this->assertSame('applied', $applied->status);
        $this->assertSame(7, $applied->installed_version);
        $this->assertNull($applied->last_apply_error);
        $this->assertNotNull($applied->last_apply_at);

        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.apply_started',
        ]);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.apply_failed',
        ]);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.apply_succeeded',
        ]);
    }

    public function test_real_agent_payload_is_accepted_without_contract_translation(): void
    {
        $context = $this->context();
        $checksum = hash('sha256', '$ORIGIN example.test.'.PHP_EOL);
        $eventId = (string) Str::uuid();

        $this->withToken($context['token'])
            ->postJson(
                '/api/agent/publications/'.$context['publication']->id.'/apply',
                [
                    'event_id' => $eventId,
                    'agent_timestamp' => now()->toIso8601String(),
                    'status' => 'applying',
                    'artifact_checksum' => $checksum,
                ],
            )
            ->assertOk()
            ->assertJsonPath('publication_id', $context['publication']->id)
            ->assertJsonPath('desired_version', 7)
            ->assertJsonPath('installed_version', null)
            ->assertJsonPath('status', 'applying');

        $this->withToken($context['token'])
            ->postJson(
                '/api/agent/publications/'.$context['publication']->id.'/apply',
                [
                    'event_id' => (string) Str::uuid(),
                    'agent_timestamp' => now()->toIso8601String(),
                    'status' => 'applied',
                    'installed_version' => 7,
                    'authoritative_serial' => $context['zone']->serial,
                    'artifact_checksum' => $checksum,
                ],
            )
            ->assertOk()
            ->assertJsonPath('installed_version', 7)
            ->assertJsonPath('status', 'applied');
    }

    public function test_duplicate_is_idempotent_and_replay_with_different_payload_is_rejected(): void
    {
        $context = $this->context();
        $eventId = (string) Str::uuid();

        $this->report($context, 'applied', [
            'event_id' => $eventId,
            'installed_version' => 7,
        ])->assertOk()
            ->assertJsonPath('idempotent', false);

        $this->report($context, 'applied', [
            'event_id' => $eventId,
            'installed_version' => 7,
        ])->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertDatabaseCount('dns_agent_publication_events', 1);

        $this->report($context, 'failed', [
            'event_id' => $eventId,
            'error' => 'outro evento',
        ])->assertConflict()
            ->assertJsonPath('error', 'event_replay');

        $this->assertSame('applied', $context['publication']->fresh()->status);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'agent.version_rejected',
            'result' => 'rejected',
        ]);
    }

    public function test_late_event_does_not_regress_applied_state(): void
    {
        $context = $this->context();

        $this->report($context, 'applied', ['installed_version' => 7])
            ->assertOk();

        $this->report($context, 'applying')
            ->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('status', 'applied');

        $this->report($context, 'failed', ['error' => 'falha tardia'])
            ->assertOk()
            ->assertJsonPath('status', 'applied');

        $publication = $context['publication']->fresh();
        $this->assertSame('applied', $publication->status);
        $this->assertSame(7, $publication->installed_version);
        $this->assertNull($publication->last_apply_error);
    }

    public function test_wrong_agent_cannot_access_or_confirm_another_destination(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra organização');

        $this->withToken($foreign['token'])
            ->getJson(
                '/api/agent/zones/'.$context['zone']->id.'/artifact'
                .'?publication='.$context['publication']->id,
            )
            ->assertNotFound();

        $this->withToken($foreign['token'])
            ->postJson(
                '/api/agent/publications/'.$context['publication']->id.'/apply',
                [
                    'event_id' => (string) Str::uuid(),
                    'status' => 'applied',
                    'installed_version' => 7,
                    'authoritative_serial' => $context['zone']->serial,
                ],
            )
            ->assertNotFound()
            ->assertJsonPath('error', 'publication_not_available');

        $this->assertSame('pending', $context['publication']->fresh()->status);
    }

    public function test_unknown_and_mismatched_versions_are_rejected(): void
    {
        $context = $this->context();

        $this->withToken($context['token'])
            ->postJson('/api/agent/publications/999999/apply', [
                'event_id' => (string) Str::uuid(),
                'status' => 'applied',
                'installed_version' => 7,
                'authoritative_serial' => $context['zone']->serial,
            ])
            ->assertNotFound()
            ->assertJsonPath('error', 'publication_not_available');

        $this->report($context, 'applied', [
            'installed_version' => 6,
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'installed_version_mismatch');

        $this->assertNull($context['publication']->fresh()->installed_version);
    }

    public function test_authentication_validation_and_error_limits_are_controlled(): void
    {
        $context = $this->context();

        $this->postJson(
            '/api/agent/publications/'.$context['publication']->id.'/apply',
            [
                'event_id' => (string) Str::uuid(),
                'status' => 'applied',
                'installed_version' => 7,
                'authoritative_serial' => $context['zone']->serial,
            ],
        )->assertUnauthorized()
            ->assertJsonMissing(['token'])
            ->assertJsonMissing(['trace']);

        $this->report($context, 'failed', [
            'error' => str_repeat('x', 2001),
        ])->assertUnprocessable()
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['token']);

        $this->report($context, 'failed', [
            'error' => str_repeat('x', 1500),
        ])->assertOk();

        $this->assertSame(
            1000,
            mb_strlen($context['publication']->fresh()->last_apply_error),
        );
    }

    public function test_web_views_show_persisted_versions_counts_and_organization_isolation(): void
    {
        $context = $this->context();
        $foreign = $this->context('Outra organização');

        $this->report($context, 'applied', ['installed_version' => 7])
            ->assertOk();

        $this->actingAs($context['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertOk()
            ->assertSee('Versão desejada')
            ->assertSee('Versão instalada confirmada')
            ->assertSee('Estado da aplicação');

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $context['zone']))
            ->assertOk()
            ->assertSee('Agentes aplicáveis')
            ->assertSee('Aplicação confirmada')
            ->assertSee('Agentes offline');

        $this->actingAs($foreign['admin'])
            ->get(route('zones.show', $context['zone']))
            ->assertNotFound();

        $this->actingAs($foreign['admin'])
            ->get(route('servers.agent.show', $context['server']))
            ->assertNotFound();
    }

    private function report(
        array $context,
        string $status,
        array $payload = [],
    ) {
        return $this->withToken($context['token'])
            ->postJson(
                '/api/agent/publications/'.$context['publication']->id.'/apply',
                [
                    'event_id' => (string) Str::uuid(),
                    'status' => $status,
                    ...($status === 'applied'
                        ? ['authoritative_serial' => $context['zone']->serial]
                        : []),
                    ...$payload,
                ],
            );
    }

    private function context(string $organizationName = 'Empresa Teste'): array
    {
        $organization = Organization::query()->create([
            'name' => $organizationName,
            'slug' => Str::slug($organizationName).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $server = DnsServer::query()->create([
            'organization_id' => $organization->id,
            'name' => 'NS '.$organization->id,
            'hostname' => 'ns'.$organization->id.'.example.test',
            'role' => 'primary',
            'environment' => 'production',
            'status' => 'online',
            'enabled' => true,
            'agent_status' => 'online',
            'last_seen_at' => now(),
        ]);

        $token = Str::random(96);
        $agent = DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => str_repeat('a', 64),
            'token_hash' => hash('sha256', $token),
            'reported_hostname' => $server->hostname,
            'registered_at' => now(),
            'last_seen_at' => now(),
        ]);

        $zone = DnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => 'example'.$organization->id.'.test',
            'kind' => 'primary',
            'serial' => 2026072901,
            'default_ttl' => 3600,
            'soa_mname' => $server->hostname,
            'soa_rname' => 'hostmaster.example.test',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 7,
            'enabled' => true,
        ]);
        $zone->servers()->attach($server->id, ['role' => 'primary']);

        $version = DnsZoneVersion::query()->create([
            'organization_id' => $organization->id,
            'dns_zone_id' => $zone->id,
            'created_by' => $admin->id,
            'version' => 7,
            'serial' => $zone->serial,
            'reason' => 'Zona publicada.',
            'snapshot' => [
                'zonefile' => '$ORIGIN example.test.'.PHP_EOL,
            ],
        ]);

        $publication = DnsAgentPublication::query()->create([
            'organization_id' => $organization->id,
            'dns_zone_version_id' => $version->id,
            'dns_server_id' => $server->id,
            'dns_agent_id' => $agent->id,
            'status' => 'pending',
        ]);

        return compact(
            'organization',
            'admin',
            'server',
            'agent',
            'token',
            'zone',
            'version',
            'publication',
        );
    }
}
