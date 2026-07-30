<?php

namespace Tests\Feature;

use App\Models\DnsAgent;
use App\Models\DnsAgentPublication;
use App\Models\DnsServer;
use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsAuthoritativeTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_tsig_lifecycle_reuses_administrative_two_factor_gate(): void
    {
        config()->set('security.admin_2fa.required', true);
        [$organization, $admin] = $this->organizationAdmin();

        $this->actingAs($admin)
            ->post(route('tsig.store'), [
                'name' => 'xfr-example',
                'algorithm' => 'hmac-sha256',
            ])
            ->assertRedirect(route('security.two-factor.setup'));

        $this->assertDatabaseCount('dns_tsig_keys', 0);

        $admin->forceFill([
            'two_factor_secret' => 'encrypted-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('tsig.store'), [
            'name' => 'xfr-example',
            'algorithm' => 'hmac-sha256',
        ])->assertSessionHasNoErrors();

        $key = DnsTsigKey::query()->sole();
        $this->assertSame($organization->id, $key->organization_id);
        $this->assertNotEmpty($key->secret);
        $this->assertNotSame(
            $key->secret,
            DB::table('dns_tsig_keys')->where('id', $key->id)->value('secret'),
        );
        $this->assertDatabaseHas('security_audits', [
            'event' => 'dns.tsig.created',
            'organization_id' => $organization->id,
        ]);

        $previous = $key->secret;
        $this->post(route('tsig.rotate', $key))->assertSessionHasNoErrors();
        $this->assertSame($previous, $key->fresh()->secret);
        $this->assertDatabaseCount('dns_tsig_keys', 2);
        $this->assertNotSame(
            $previous,
            DnsTsigKey::query()->whereKeyNot($key->id)->sole()->secret,
        );

        $foreignOrganization = Organization::factory()->create();
        $foreignKey = DnsTsigKey::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'foreign-xfr-key',
            'algorithm' => 'hmac-sha256',
            'secret' => base64_encode(random_bytes(32)),
            'enabled' => true,
        ]);
        $primary = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'primary',
        ]);
        $zone = $this->zone($organization, $admin, $primary);

        $this->post(route('tsig.associate', $zone), [
            'dns_tsig_key_id' => $foreignKey->id,
        ])->assertSessionHasErrors('dns_tsig_key_id');

        $this->assertNull($zone->fresh()->dns_tsig_key_id);
    }

    public function test_manifest_builds_distinct_primary_and_secondary_contracts(): void
    {
        [$organization, $admin] = $this->organizationAdmin();
        [$primary, $primaryAgent, $primaryToken] = $this->serverAgent(
            $organization,
            'primary',
            '192.0.2.10',
        );
        [$secondary, $secondaryAgent, $secondaryToken] = $this->serverAgent(
            $organization,
            'secondary',
            '192.0.2.11',
        );
        $key = DnsTsigKey::query()->create([
            'organization_id' => $organization->id,
            'name' => 'xfr-example',
            'algorithm' => 'hmac-sha256',
            'secret' => base64_encode(random_bytes(32)),
            'enabled' => true,
            'created_by' => $admin->id,
        ]);
        $zone = $this->zone($organization, $admin, $primary, $key);
        $zone->servers()->attach($secondary->id, ['role' => 'secondary']);
        $version = $zone->versions()->firstOrFail();

        foreach ([[$primary, $primaryAgent], [$secondary, $secondaryAgent]] as [$server, $agent]) {
            DnsAgentPublication::query()->create([
                'organization_id' => $organization->id,
                'dns_zone_version_id' => $version->id,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'status' => 'pending',
            ]);
        }

        $primaryManifest = $this->withToken($primaryToken)
            ->getJson('/api/agent/zones')
            ->assertOk()
            ->assertJsonPath('zones.0.type', 'primary')
            ->assertJsonPath('zones.0.transfer.secondary_addresses.0', '192.0.2.11')
            ->assertJsonPath('zones.0.transfer.tsig.name', 'xfr-example')
            ->json('zones.0');

        $this->assertNotNull($primaryManifest['artifact_url']);

        $secondaryManifest = $this->withToken($secondaryToken)
            ->getJson('/api/agent/zones')
            ->assertOk()
            ->assertJsonPath('zones.0.type', 'secondary')
            ->assertJsonPath('zones.0.transfer.primary_addresses.0', '192.0.2.10')
            ->assertJsonPath('zones.0.artifact_url', null)
            ->json('zones.0');

        $this->assertSame($key->secret, $secondaryManifest['transfer']['tsig']['secret']);
        $this->withToken($secondaryToken)
            ->getJson('/api/agent/zones/'.$zone->id.'/artifact')
            ->assertNotFound();
    }

    public function test_applied_state_requires_real_matching_soa_serial(): void
    {
        [$organization, $admin] = $this->organizationAdmin();
        [$primary, $agent, $token] = $this->serverAgent(
            $organization,
            'primary',
            '192.0.2.10',
        );
        $zone = $this->zone($organization, $admin, $primary);
        $version = $zone->versions()->firstOrFail();
        $publication = DnsAgentPublication::query()->create([
            'organization_id' => $organization->id,
            'dns_zone_version_id' => $version->id,
            'dns_server_id' => $primary->id,
            'dns_agent_id' => $agent->id,
            'status' => 'pending',
        ]);

        $this->withToken($token)->postJson(
            route('api.agent.publications.apply', $publication),
            [
                'event_id' => (string) Str::uuid(),
                'status' => 'applied',
                'installed_version' => $version->version,
                'authoritative_serial' => $version->serial - 1,
            ],
        )->assertUnprocessable()
            ->assertJsonPath('error', 'authoritative_serial_mismatch');

        $this->withToken($token)->postJson(
            route('api.agent.publications.apply', $publication),
            [
                'event_id' => (string) Str::uuid(),
                'status' => 'applied',
                'installed_version' => $version->version,
                'authoritative_serial' => $version->serial,
            ],
        )->assertOk()
            ->assertJsonPath('authoritative_serial', $version->serial);

        $this->assertSame($version->serial, $publication->fresh()->reported_serial);
        $this->assertNotNull($publication->fresh()->serial_confirmed_at);
    }

    private function organizationAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'must_change_password' => false,
        ]);
        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$organization, $admin];
    }

    private function serverAgent(
        Organization $organization,
        string $role,
        string $address,
    ): array {
        $server = DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'role' => $role,
            'ipv4_address' => $address,
            'status' => 'online',
            'agent_status' => 'online',
            'enabled' => true,
        ]);
        $token = Str::random(96);
        $agent = DnsAgent::query()->create([
            'organization_id' => $organization->id,
            'dns_server_id' => $server->id,
            'agent_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', $address),
            'token_hash' => hash('sha256', $token),
            'registered_at' => now(),
        ]);

        return [$server, $agent, $token];
    }

    private function zone(
        Organization $organization,
        User $admin,
        DnsServer $primary,
        ?DnsTsigKey $key = null,
    ): DnsZone {
        $zone = DnsZone::query()->create([
            'organization_id' => $organization->id,
            'dns_tsig_key_id' => $key?->id,
            'name' => 'example'.$organization->id.'.test',
            'kind' => 'primary',
            'serial' => 2026073001,
            'default_ttl' => 3600,
            'soa_mname' => $primary->hostname,
            'soa_rname' => 'hostmaster.example.test',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'published',
            'version' => 7,
            'enabled' => true,
        ]);
        $zone->servers()->attach($primary->id, ['role' => 'primary']);
        DnsZoneVersion::query()->create([
            'organization_id' => $organization->id,
            'dns_zone_id' => $zone->id,
            'created_by' => $admin->id,
            'version' => 7,
            'serial' => $zone->serial,
            'reason' => 'Zona publicada.',
            'snapshot' => ['zonefile' => '$ORIGIN '.$zone->name.'.'.PHP_EOL],
        ]);

        return $zone;
    }
}
