<?php

namespace Tests\Feature;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DnsZonePtrSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_ptr_sync_updates_only_matching_identities_and_bumps_zone(): void
    {
        $context = $this->context();
        $zone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        $ns1 = $this->seedPtr($zone, '10.2.0.192.in-addr.arpa.', 'generic-host-10.example.');
        $ns2 = $this->seedPtr($zone, '20.2.0.192.in-addr.arpa.', 'generic-host-20.example.');
        $unrelated = $this->seedPtr($zone, '5.2.0.192.in-addr.arpa.', 'other-host.example.');

        $originalVersion = $zone->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $zone))
            ->assertRedirect();

        $this->assertSame('ns1.provider.example.', $ns1->fresh()->content);
        $this->assertSame('ns2.provider.example.', $ns2->fresh()->content);
        $this->assertSame('other-host.example.', $unrelated->fresh()->content);
        $this->assertGreaterThan($originalVersion, $zone->fresh()->version);
    }

    public function test_ptr_sync_creates_missing_ptr_record(): void
    {
        $context = $this->context();
        $zone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $zone))
            ->assertRedirect();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => '10.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'ns1.provider.example.',
        ]);
        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => '20.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'ns2.provider.example.',
        ]);
    }

    public function test_ptr_sync_skips_identity_outside_zone_network(): void
    {
        $context = $this->context();
        $zone = $this->createReverseZone($context, '99.0.192.in-addr.arpa');

        $originalVersion = $zone->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $zone))
            ->assertRedirect();

        $this->assertSame(
            0,
            DnsRecord::query()->where('dns_zone_id', $zone->id)->where('type', 'PTR')->count(),
        );
        $this->assertSame($originalVersion, $zone->fresh()->version);
    }

    public function test_ptr_sync_rejects_non_reverse_zone_and_unauthorized_role(): void
    {
        $context = $this->context();
        $forwardZone = $this->createZone($context);

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $forwardZone))
            ->assertNotFound();

        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        foreach (['operator', 'viewer'] as $role) {
            $member = $this->member($context['organization'], $role);

            $this->actingAs($member)
                ->post(route('zones.ptr-sync', $reverseZone))
                ->assertForbidden();
        }
    }

    public function test_ptr_sync_is_idempotent_on_second_run(): void
    {
        $context = $this->context();
        $zone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $zone))
            ->assertRedirect();

        $versionAfterFirstRun = $zone->fresh()->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.ptr-sync', $zone))
            ->assertRedirect()
            ->assertSessionHas('status', 'Nenhum registro PTR precisou de alteração.');

        $this->assertSame($versionAfterFirstRun, $zone->fresh()->version);
        $this->assertSame(
            2,
            DnsRecord::query()->where('dns_zone_id', $zone->id)->where('type', 'PTR')->count(),
        );
    }

    private function seedPtr(DnsZone $zone, string $name, string $content): DnsRecord
    {
        return DnsRecord::query()->create([
            'organization_id' => $zone->organization_id,
            'dns_zone_id' => $zone->id,
            'name' => $name,
            'type' => 'PTR',
            'ttl' => null,
            'priority' => null,
            'content' => $content,
            'enabled' => true,
        ]);
    }

    private function createReverseZone(array $context, string $name): DnsZone
    {
        $this->actingAs($context['admin'])
            ->post(route('zones.store'), $this->zonePayload($context, [
                'name' => $name,
            ]))
            ->assertSessionHasNoErrors();

        return DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    private function createZone(array $context): DnsZone
    {
        $this->actingAs($context['admin'])
            ->post(route('zones.store'), $this->zonePayload($context))
            ->assertSessionHasNoErrors();

        return DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', 'example.com')
            ->firstOrFail();
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

    private function context(string $name = 'Empresa Teste'): array
    {
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

        return compact(
            'organization',
            'admin',
            'primary',
            'secondary',
            'firstIdentity',
            'secondIdentity',
            'profile',
        );
    }
}
