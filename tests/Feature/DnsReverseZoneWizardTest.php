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

class DnsReverseZoneWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_creates_ipv4_reverse_zone_with_automatic_ns(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '2.0.192.in-addr.arpa')
            ->firstOrFail();

        $this->assertTrue($zone->isReverseZone());
        $this->assertFalse($zone->isIpv6ReverseZone());
        $this->assertSame(2, $zone->records()->where('type', 'NS')->count());
    }

    public function test_wizard_creates_ipv6_reverse_zone(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv6',
                'cidr' => '2001:db8::/32',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '8.b.d.0.1.0.0.2.ip6.arpa')
            ->firstOrFail();

        $this->assertTrue($zone->isIpv6ReverseZone());
        $this->assertFalse($zone->isReverseZone());
    }

    public function test_wizard_rejects_non_octet_aligned_ipv4_block(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/25',
            ]))
            ->assertSessionHasErrors('cidr');

        $this->assertDatabaseCount('dns_zones', 0);
    }

    public function test_wizard_rejects_duplicate_reverse_zone(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
            ]))
            ->assertSessionHasErrors('cidr');

        $this->assertSame(
            1,
            DnsZone::query()->where('name', '2.0.192.in-addr.arpa')->count(),
        );
    }

    public function test_wizard_is_forbidden_for_non_admin_roles(): void
    {
        $context = $this->context();

        foreach (['operator', 'viewer'] as $role) {
            $member = $this->member($context['organization'], $role);

            $this->actingAs($member)
                ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                    'family' => 'ipv4',
                    'cidr' => '192.0.2.0/24',
                ]))
                ->assertForbidden();
        }
    }

    public function test_generate_ptr_from_forward_zone_matches_only_addresses_inside_block(): void
    {
        $context = $this->context();
        $forwardZone = $this->createZone($context, 'example.com');

        DnsRecord::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $forwardZone->id,
            'name' => 'inside',
            'type' => 'A',
            'ttl' => null,
            'priority' => null,
            'content' => '192.0.2.10',
            'enabled' => true,
        ]);

        DnsRecord::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $forwardZone->id,
            'name' => 'outside',
            'type' => 'A',
            'ttl' => null,
            'priority' => null,
            'content' => '203.0.113.5',
            'enabled' => true,
        ]);

        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');
        $originalVersion = $reverseZone->version;

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.generate-ptr', $reverseZone), [
                'forward_zone_id' => $forwardZone->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '10.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'inside.example.com.',
        ]);

        $this->assertSame(
            1,
            DnsRecord::query()
                ->where('dns_zone_id', $reverseZone->id)
                ->where('type', 'PTR')
                ->count(),
        );

        $this->assertGreaterThan($originalVersion, $reverseZone->fresh()->version);
    }

    public function test_generate_ptr_rejects_non_reverse_zone(): void
    {
        $context = $this->context();
        $forwardZone = $this->createZone($context, 'example.com');
        $otherZone = $this->createZone($context, 'other.example.com');

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.generate-ptr', $forwardZone), [
                'forward_zone_id' => $otherZone->id,
            ])
            ->assertNotFound();
    }

    private function reversePayload(array $context, array $overrides = []): array
    {
        return array_merge([
            'dns_nameserver_profile_id' => $context['profile']->id,
            'primary_server_id' => $context['primary']->id,
            'secondary_server_id' => $context['secondary']->id,
        ], $overrides);
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

    private function createZone(array $context, string $name): DnsZone
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
