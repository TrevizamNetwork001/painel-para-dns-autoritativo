<?php

namespace Tests\Feature;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use App\Services\ReverseZoneNameCalculator;
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

    public function test_reverse_zone_page_hides_redundant_reverse_area(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv6',
                'cidr' => '2001:db8::/32',
            ]))
            ->assertSessionHasNoErrors();

        $reverseZone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '8.b.d.0.1.0.0.2.ip6.arpa')
            ->firstOrFail();

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $reverseZone))
            ->assertOk()
            ->assertDontSee('<h2 id="reverse-modal-title">', false)
            ->assertDontSee('data-domain-tab="reverse"', false)
            ->assertDontSee('data-domain-panel="reverse"', false);
    }

    public function test_ipv4_reverse_zone_page_lists_ptr_records_in_numeric_order(): void
    {
        $context = $this->context();
        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        foreach ([255, 10, 2, 0] as $octet) {
            DnsRecord::query()->create([
                'organization_id' => $context['organization']->id,
                'dns_zone_id' => $reverseZone->id,
                'name' => $octet.'.2.0.192.in-addr.arpa',
                'type' => 'PTR',
                'ttl' => null,
                'priority' => null,
                'content' => 'host-'.$octet.'.example.',
                'enabled' => true,
            ]);
        }

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $reverseZone))
            ->assertOk()
            ->assertDontSee('data-record-type="NS"', false)
            ->assertSee('data-record-type="PTR"', false)
            ->assertSeeInOrder([
                'host-0.example.',
                'host-2.example.',
                'host-10.example.',
                'host-255.example.',
            ]);

        $this->assertSame(2, $reverseZone->records()->where('type', 'NS')->count());
    }

    public function test_ipv6_reverse_zone_page_translates_apex_and_sorts_ptr_records(): void
    {
        $context = $this->context();
        $reverseZone = $this->createReverseZone(
            $context,
            'c.2.1.7.4.0.8.2.ip6.arpa',
        );
        $calculator = app(ReverseZoneNameCalculator::class);

        foreach (['2804:712c::ff', '2804:712c::10', '2804:712c::2'] as $ip) {
            DnsRecord::query()->create([
                'organization_id' => $context['organization']->id,
                'dns_zone_id' => $reverseZone->id,
                'name' => $calculator->ipv6ToPtrName($ip),
                'type' => 'PTR',
                'ttl' => null,
                'priority' => null,
                'content' => 'host-'.str_replace(':', '-', $ip).'.example.',
                'enabled' => true,
            ]);
        }

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $reverseZone))
            ->assertOk()
            ->assertSee('title="c.2.1.7.4.0.8.2.ip6.arpa"', false)
            ->assertSeeInOrder([
                'host-2804-712c--2.example.',
                'host-2804-712c--10.example.',
                'host-2804-712c--ff.example.',
            ]);
    }

    public function test_forward_zone_page_lists_address_records_in_numeric_order(): void
    {
        $context = $this->context();
        $zone = $this->createZone($context, 'example.com');

        foreach ([255, 10, 2] as $octet) {
            foreach (['AAAA', 'A'] as $type) {
                DnsRecord::query()->create([
                    'organization_id' => $context['organization']->id,
                    'dns_zone_id' => $zone->id,
                    'name' => 'host-'.$octet,
                    'type' => $type,
                    'ttl' => null,
                    'priority' => null,
                    'content' => $type === 'A'
                        ? '192.0.2.'.$octet
                        : '2001:db8::'.dechex($octet),
                    'enabled' => true,
                ]);
            }
        }

        $this->actingAs($context['admin'])
            ->get(route('zones.show', $zone))
            ->assertOk()
            ->assertSeeInOrder([
                '192.0.2.2',
                '2001:db8::2',
                '192.0.2.10',
                '2001:db8::a',
                '192.0.2.255',
                '2001:db8::ff',
            ]);
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

    public function test_wizard_splits_slash22_and_skips_existing_slash24_zone(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.168.20.0/24',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.168.20.0/22',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '3 zona(s)')
                && str_contains($status, '1 já existente'));

        foreach (range(20, 23) as $thirdOctet) {
            $this->assertDatabaseHas('dns_zones', [
                'organization_id' => $context['organization']->id,
                'name' => sprintf('%d.168.192.in-addr.arpa', $thirdOctet),
            ]);
        }
    }

    public function test_wizard_rejects_misaligned_ipv4_range(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.168.21.0/22',
            ]))
            ->assertSessionHasErrors('cidr');

        $this->assertDatabaseCount('dns_zones', 0);
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

    public function test_wizard_saves_ptr_name_template_for_ipv4(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_template' => 'host-$',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '2.0.192.in-addr.arpa')
            ->firstOrFail();

        $this->assertSame('host-$', $zone->ptr_name_template);
    }

    public function test_wizard_saves_selected_ptr_name_format_for_ipv4(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_format' => 'ip',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '2.0.192.in-addr.arpa')
            ->firstOrFail();

        $this->assertSame('ip-$', $zone->ptr_name_template);
    }

    public function test_wizard_saves_legacy_full_ip_ptr_formats(): void
    {
        $context = $this->context();

        foreach ([
            'iprede' => ['192.0.2.0/24', '2.0.192.in-addr.arpa', 'ip-{ip-dash}'],
            'rede' => ['198.51.100.0/24', '100.51.198.in-addr.arpa', '{ip-dash}'],
        ] as $format => [$cidr, $zoneName, $template]) {
            $this->actingAs($context['admin'])
                ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                    'family' => 'ipv4',
                    'cidr' => $cidr,
                    'ptr_name_format' => $format,
                ]))
                ->assertSessionHasNoErrors();

            $zone = DnsZone::query()
                ->where('organization_id', $context['organization']->id)
                ->where('name', $zoneName)
                ->firstOrFail();
            $this->assertSame($template, $zone->ptr_name_template);
        }
    }

    public function test_wizard_script_format_generates_ptr_target_with_full_ipv4(): void
    {
        $context = $this->context();
        $forwardZone = $this->createZone($context, 'example.com');

        DnsRecord::query()->create([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $forwardZone->id,
            'name' => 'cliente',
            'type' => 'A',
            'ttl' => null,
            'priority' => null,
            'content' => '192.0.2.10',
            'enabled' => true,
        ]);

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_format' => 'script',
            ]))
            ->assertSessionHasNoErrors();

        $reverseZone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '2.0.192.in-addr.arpa')
            ->firstOrFail();

        $this->assertSame('{ip}', $reverseZone->ptr_name_template);

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.generate-ptr', $reverseZone), [
                'forward_zone_id' => $forwardZone->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '10.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => '192.0.2.10.example.com.',
        ]);
    }

    public function test_wizard_saves_custom_ptr_name_format_for_ipv4(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_format' => 'custom',
                'ptr_name_custom' => 'cliente-$',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '2.0.192.in-addr.arpa')
            ->firstOrFail();

        $this->assertSame('cliente-$', $zone->ptr_name_template);
    }

    public function test_wizard_requires_value_for_custom_ptr_name_format(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_format' => 'custom',
            ]))
            ->assertSessionHasErrors('ptr_name_custom');

        $this->assertDatabaseCount('dns_zones', 0);
    }

    public function test_wizard_ignores_ptr_name_template_for_ipv6(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv6',
                'cidr' => '2001:db8::/32',
                'ptr_name_template' => 'host-$',
            ]))
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '8.b.d.0.1.0.0.2.ip6.arpa')
            ->firstOrFail();

        $this->assertNull($zone->ptr_name_template);
    }

    public function test_wizard_rejects_invalid_ptr_name_template(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv4',
                'cidr' => '192.0.2.0/24',
                'ptr_name_template' => 'sem-placeholder',
            ]))
            ->assertSessionHasErrors('ptr_name_template');

        $this->assertDatabaseCount('dns_zones', 0);
    }

    public function test_generate_ptr_uses_configured_name_template(): void
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

        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');
        $reverseZone->forceFill(['ptr_name_template' => 'host-$'])->save();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.generate-ptr', $reverseZone), [
                'forward_zone_id' => $forwardZone->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '10.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'host-10.example.com.',
        ]);
    }

    public function test_generate_ptr_template_joins_multiple_host_octets_for_slash16_block(): void
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
            'content' => '198.18.5.7',
            'enabled' => true,
        ]);

        $reverseZone = $this->createReverseZone($context, '18.198.in-addr.arpa');
        $reverseZone->forceFill(['ptr_name_template' => 'host-$'])->save();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.generate-ptr', $reverseZone), [
                'forward_zone_id' => $forwardZone->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '7.5.18.198.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'host-5-7.example.com.',
        ]);
    }

    public function test_zone_settings_update_saves_ptr_name_template(): void
    {
        $context = $this->context();
        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        $this->actingAs($context['admin'])
            ->put(route('zones.update', $reverseZone), [
                'kind' => 'primary',
                'dns_nameserver_profile_id' => $context['profile']->id,
                'default_ttl' => 3600,
                'soa_rname' => 'hostmaster.'.$reverseZone->name,
                'soa_refresh' => 3600,
                'soa_retry' => 900,
                'soa_expire' => 1209600,
                'soa_minimum' => 300,
                'primary_server_id' => $context['primary']->id,
                'secondary_server_id' => $context['secondary']->id,
                'ptr_name_template' => 'ip-$',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('ip-$', $reverseZone->fresh()->ptr_name_template);
    }

    public function test_ptr_record_name_accepts_short_ip_and_normalizes_to_full_name(): void
    {
        $context = $this->context();
        $reverseZone = $this->createReverseZone($context, '2.0.192.in-addr.arpa');

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $reverseZone), [
                'name' => '192.0.2.10',
                'type' => 'PTR',
                'content' => 'host.example.com.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '10.2.0.192.in-addr.arpa',
            'type' => 'PTR',
            'content' => 'host.example.com.',
        ]);
    }

    public function test_ptr_record_name_in_ipv6_zone_accepts_short_ip(): void
    {
        $context = $this->context();

        $this->actingAs($context['admin'])
            ->post(route('zones.reverse.store'), $this->reversePayload($context, [
                'family' => 'ipv6',
                'cidr' => '2001:db8::/32',
            ]))
            ->assertSessionHasNoErrors();

        $reverseZone = DnsZone::query()
            ->where('organization_id', $context['organization']->id)
            ->where('name', '8.b.d.0.1.0.0.2.ip6.arpa')
            ->firstOrFail();

        $this->actingAs($context['admin'])
            ->post(route('zones.records.store', $reverseZone), [
                'name' => '2001:db8::242',
                'type' => 'PTR',
                'content' => 'host.example.com.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $reverseZone->id,
            'name' => '2.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            'type' => 'PTR',
            'content' => 'host.example.com.',
        ]);
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
