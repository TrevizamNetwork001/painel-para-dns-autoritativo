<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsBindOperation;
use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use App\Services\BindZoneRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsZoneWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_save_updates_database_without_publishing(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);
        $version = $zone->version;

        $this->actingAs($context['admin'])
            ->put(
                route('zones.update', $zone),
                $this->zonePayload($context, [
                    'default_ttl' => 7200,
                    'notes' => 'Alteração apenas no banco',
                ]),
            )
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Zona salva. As alterações ainda não foram publicadas.',
            );

        $zone->refresh();

        $this->assertSame(7200, $zone->default_ttl);
        $this->assertSame('draft', $zone->status);
        $this->assertSame($version + 1, $zone->version);
        $this->assertDatabaseMissing('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Zona publicada.',
        ]);
    }

    public function test_foreign_profile_is_rejected_and_foreign_zone_is_hidden(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);
        $foreign = $this->context('Outra organização');

        $this->actingAs($context['admin'])
            ->put(
                route('zones.update', $zone),
                $this->zonePayload($context, [
                    'dns_nameserver_profile_id' => $foreign['profile']->id,
                ]),
            )
            ->assertSessionHasErrors('dns_nameserver_profile_id');

        $this->actingAs($foreign['admin'])
            ->get(route('zones.show', $zone))
            ->assertNotFound();

        $this->actingAs($foreign['admin'])
            ->post(route('zones.publish', $zone))
            ->assertNotFound();
    }

    public function test_operator_and_viewer_follow_read_only_permission(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);

        foreach (['operator', 'viewer'] as $role) {
            $member = $this->member($context['organization'], $role);

            $this->actingAs($member)
                ->get(route('zones.show', $zone))
                ->assertOk()
                ->assertDontSee('action="'.route('zones.publish', $zone).'"', false);

            $this->actingAs($member)
                ->put(
                    route('zones.update', $zone),
                    $this->zonePayload($context),
                )
                ->assertForbidden();

            $this->actingAs($member)
                ->post(route('zones.publish', $zone))
                ->assertForbidden();
        }
    }

    public function test_record_create_update_and_delete_remain_pending(): void
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
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Registro DNS adicionado. As alterações ainda não foram publicadas.',
            );

        $this->assertDatabaseHas('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Registro adicionado: www A → 192.0.2.40.',
        ]);

        $record = $zone->records()
            ->where('name', 'www')
            ->firstOrFail();

        $this->actingAs($context['admin'])
            ->put(route('zones.records.update', [$zone, $record]), [
                'name' => 'web',
                'type' => 'AAAA',
                'ttl' => 600,
                'content' => '2001:db8::40',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_records', [
            'id' => $record->id,
            'name' => 'web',
            'type' => 'AAAA',
            'content' => '2001:db8::40',
        ]);

        $this->assertDatabaseHas('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Registro atualizado: web AAAA → 2001:db8::40 (era www A → 192.0.2.40).',
        ]);

        $this->actingAs($context['admin'])
            ->delete(route('zones.records.destroy', [$zone, $record]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('dns_records', ['id' => $record->id]);
        $this->assertNotSame('published', $zone->fresh()->status);

        $this->assertDatabaseHas('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Registro removido: web AAAA → 2001:db8::40.',
        ]);
    }

    public function test_invalid_and_foreign_zone_records_cannot_be_manipulated(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $zone), [
                'name' => 'bad name',
                'type' => 'A',
                'ttl' => 10,
                'content' => "192.0.2.1\nmalicious",
            ])
            ->assertSessionHasErrors('ttl');

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $zone), [
                'name' => 'bad name',
                'type' => 'A',
                'ttl' => 300,
                'content' => "192.0.2.1\nmalicious",
            ])
            ->assertSessionHasErrors('name');

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $zone), [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 300,
                'content' => "192.0.2.1\nmalicious",
            ])
            ->assertSessionHasErrors('content');

        $otherZone = DnsZone::query()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'other.example',
            'kind' => 'primary',
            'serial' => 2026072900,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.other.example',
            'soa_rname' => 'hostmaster.other.example',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
        ]);

        $record = $otherZone->records()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'www',
            'type' => 'A',
            'content' => '192.0.2.50',
            'enabled' => true,
        ]);

        $this->actingAs($context['admin'])
            ->delete(route('zones.records.destroy', [$zone, $record]))
            ->assertNotFound();

        $this->actingAs($context['admin'])
            ->put(route('zones.records.update', [$zone, $record]), [
                'name' => 'www',
                'type' => 'A',
                'content' => '192.0.2.51',
            ])
            ->assertNotFound();
    }

    public function test_explicit_publish_exposes_artifact_and_is_idempotent(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);
        $version = $zone->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'Publicação concluída. O artefato está disponível para os agentes configurados.',
            );

        $zone->refresh();

        $this->assertSame('published', $zone->status);
        $this->assertSame($version + 1, $zone->version);
        $this->assertDatabaseHas('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'version' => $zone->version,
            'reason' => 'Zona publicada.',
        ]);

        $this->withToken($context['agentTokens'][0])
            ->getJson('/api/agent/zones')
            ->assertOk()
            ->assertJsonPath('zones.0.id', $zone->id)
            ->assertJsonPath('zones.0.version', $zone->version);

        $this->withToken($context['agentTokens'][0])
            ->getJson('/api/agent/zones/'.$zone->id.'/artifact')
            ->assertOk()
            ->assertHeader('X-DNS-Zone-Version', (string) $zone->version)
            ->assertSee('$ORIGIN example.com.', false);

        $publishedVersion = $zone->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status_go_tab', 'configuration')
            ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Sem alterações desde a última publicação.'));

        $this->assertSame($publishedVersion, $zone->fresh()->version);
        $this->assertSame(
            1,
            $zone->versions()->where('reason', 'Zona publicada.')->count(),
        );

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSee('Última publicação')
            ->assertSee('versão '.$publishedVersion);
    }

    public function test_invalid_or_unavailable_zone_is_not_published(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $zone->records()
            ->where('type', 'NS')
            ->firstOrFail()
            ->delete();

        $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasErrors('zone');

        $this->assertSame('draft', $zone->fresh()->status);
        $this->assertDatabaseMissing('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Zona publicada.',
        ]);

        $context = $this->context('Sem agente');
        $zone = $this->createZone($context);

        $response = $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasErrors('zone');

        $this->assertStringNotContainsString(
            '/opt/',
            (string) $response->getSession()->get('errors'),
        );
        $this->assertStringNotContainsString(
            'token',
            Str::lower((string) $response->getSession()->get('errors')),
        );
        $this->assertSame('draft', $zone->fresh()->status);
    }

    public function test_artifact_generation_failure_is_controlled_and_rolled_back(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);
        $version = $zone->version;

        $this->mock(BindZoneRenderer::class)
            ->shouldReceive('render')
            ->once()
            ->andThrow(new \RuntimeException('/opt/private/token-secret'));

        $response = $this->actingAs($context['admin'])
            ->post(route('zones.publish', $zone))
            ->assertSessionHasErrors('zone');

        $errors = implode(
            ' ',
            $response->getSession()
                ->get('errors')
                ->getBag('default')
                ->all(),
        );

        $this->assertStringNotContainsString('/opt/', $errors);
        $this->assertStringNotContainsString('token-secret', $errors);
        $this->assertSame($version, $zone->fresh()->version);
        $this->assertSame('draft', $zone->fresh()->status);
        $this->assertDatabaseMissing('dns_zone_versions', [
            'dns_zone_id' => $zone->id,
            'reason' => 'Zona publicada.',
        ]);
    }

    public function test_publish_and_sync_publishes_and_creates_apply_operations_for_all_servers(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->assertSame('draft', $zone->status);

        $response = $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $zone->refresh();

        $this->assertSame('published', $zone->status);

        $targets = $response->json('targets');
        $this->assertCount(2, $targets);

        foreach ($targets as $target) {
            $this->assertNull($target['skipped']);
            $this->assertNotEmpty($target['status_url']);

            $this->assertDatabaseHas('dns_bind_operations', [
                'dns_server_id' => $target['server_id'],
                'action' => 'apply_zones',
                'status' => 'authorized',
            ]);
        }
    }

    public function test_publish_and_sync_skips_server_already_synchronized(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk();

        // Simula que ambos os servidores já confirmaram a aplicação desta
        // versão — sem nenhuma mudança nova, uma segunda chamada não deve
        // criar operação nova pra nenhum dos dois.
        DnsAgentPublication::query()
            ->whereIn('dns_zone_version_id', $zone->versions()->pluck('id'))
            ->update(['status' => 'applied']);

        $operationsBefore = DnsBindOperation::query()->count();

        $response = $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $targets = collect($response->json('targets'))->keyBy('server_id');

        $this->assertSame('já sincronizado', $targets[$context['primary']->id]['skipped']);
        $this->assertSame('já sincronizado', $targets[$context['secondary']->id]['skipped']);
        $this->assertSame($operationsBefore, DnsBindOperation::query()->count());
        $response->assertJsonPath('published', false)->assertJsonPath('nothing_new', true);
    }

    public function test_publish_and_sync_flags_first_publication_as_new_content(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk()
            ->assertJsonPath('published', true)
            ->assertJsonPath('nothing_new', false);
    }

    public function test_publish_and_sync_with_pending_server_is_not_reported_as_nothing_new(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk();

        // Zona já publicada, mas as publicações ainda estão pendentes nos servidores:
        // não é "sem alterações" — há trabalho de sincronização a fazer.
        $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertOk()
            ->assertJsonPath('published', false)
            ->assertJsonPath('nothing_new', false);
    }

    public function test_zone_page_offers_go_to_configuration_after_publish_without_changes(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $this->actingAs($context['admin'])->post(route('zones.publish', $zone));

        $this->actingAs($context['admin'])
            ->followingRedirects()
            ->post(route('zones.publish', $zone))
            ->assertOk()
            ->assertSee('Sem alterações desde a última publicação.')
            ->assertSee('data-go-tab="configuration"', false);
    }

    public function test_publish_and_sync_returns_422_for_invalid_zone(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);

        $zone->records()->where('type', 'NS')->firstOrFail()->delete();

        $this->actingAs($context['admin'])
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame('draft', $zone->fresh()->status);
    }

    public function test_publish_and_sync_requires_write_permission(): void
    {
        $context = $this->context(withAgents: true);
        $zone = $this->createZone($context);
        $viewer = $this->member($context['organization'], 'viewer');

        $this->actingAs($viewer)
            ->postJson(route('zones.publish-and-sync', $zone))
            ->assertForbidden();

        $this->assertSame('draft', $zone->fresh()->status);
    }

    public function test_private_zone_routes_redirect_guests(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context);
        $record = $zone->records()->firstOrFail();

        Auth::logout();

        $this->post(route('zones.store'))->assertRedirect(route('login'));
        $this->get(route('zones.show', $zone))->assertRedirect(route('login'));
        $this->put(route('zones.update', $zone))->assertRedirect(route('login'));
        $this->post(route('zones.records.store', $zone))
            ->assertRedirect(route('login'));
        $this->put(route('zones.records.update', [$zone, $record]))
            ->assertRedirect(route('login'));
        $this->delete(route('zones.records.destroy', [$zone, $record]))
            ->assertRedirect(route('login'));
        $this->post(route('zones.publish', $zone))
            ->assertRedirect(route('login'));
    }

    private function createZone(array $context): DnsZone
    {
        $this->actingAs($context['admin'])
            ->post(route('zones.store'), $this->zonePayload($context))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', 'example.com')
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

        $admin = $this->member($organization, 'organization_admin');

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

        $agentTokens = $withAgents
            ? [
                $this->createAgent($organization, $primary),
                $this->createAgent($organization, $secondary),
            ]
            : [];

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

        return compact(
            'organization',
            'admin',
            'primary',
            'secondary',
            'profile',
            'tsigKey',
            'agentTokens',
        );
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
