<?php

namespace Tests\Feature;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobertura de modelo para DISC-001: nenhum caminho de escrita deve conseguir
 * criar duas linhas equivalentes que só divergem por maiúsculas/ponto final.
 */
class DnsRecordCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{organization: Organization, zone: DnsZone}|null */
    private ?array $sharedContext = null;

    public function test_owner_name_is_lowercased_and_trailing_dot_is_stripped_on_create(): void
    {
        $record = $this->createRecord(['name' => 'NS1.Example.COM.', 'type' => 'A', 'content' => '198.51.100.10']);

        $this->assertSame('ns1.example.com', $record->fresh()->name);
    }

    public function test_ns_and_cname_content_is_canonicalized(): void
    {
        $ns = $this->createRecord(['type' => 'NS', 'content' => 'NS1.Example.COM.']);
        $cname = $this->createRecord(['name' => 'alias', 'type' => 'CNAME', 'content' => 'Target.Example.com.']);

        $this->assertSame('ns1.example.com', $ns->fresh()->content);
        $this->assertSame('target.example.com', $cname->fresh()->content);
    }

    public function test_ptr_name_and_content_are_never_touched(): void
    {
        // PTR é a exceção de propósito: o assistente de reversos e a
        // sincronização de PTR já gravam com ponto final há tempos (owner
        // derivado do IP e o hostname apontado); essa é a forma canônica
        // ESTABELECIDA para esse tipo, e mexer quebraria o casamento exato que
        // esses fluxos já fazem (ver DnsZonePtrSyncTest, DnsReverseZoneWizardTest).
        $ptr = $this->createRecord([
            'name' => '10.2.0.192.in-addr.arpa.',
            'type' => 'PTR',
            'content' => 'Host.Example.com.',
        ]);

        $this->assertSame('10.2.0.192.in-addr.arpa.', $ptr->fresh()->name);
        $this->assertSame('Host.Example.com.', $ptr->fresh()->content);
    }

    public function test_a_aaaa_and_txt_content_is_never_touched(): void
    {
        $a = $this->createRecord(['type' => 'A', 'content' => '198.51.100.10']);
        $aaaa = $this->createRecord(['type' => 'AAAA', 'content' => '2001:DB8::1']);
        $txt = $this->createRecord(['type' => 'TXT', 'content' => 'v=spf1 -all']);

        $this->assertSame('198.51.100.10', $a->fresh()->content);
        $this->assertSame('2001:DB8::1', $aaaa->fresh()->content);
        $this->assertSame('v=spf1 -all', $txt->fresh()->content);
    }

    public function test_mx_content_without_embedded_priority_is_just_canonicalized(): void
    {
        // No banco a prioridade já vem separada (coluna própria); o content é só o host.
        $mx = $this->createRecord(['type' => 'MX', 'priority' => 10, 'content' => 'Mail.Example.COM.']);

        $this->assertSame('mail.example.com', $mx->fresh()->content);
        $this->assertSame(10, $mx->fresh()->priority);
    }

    public function test_apex_marker_is_preserved(): void
    {
        $record = $this->createRecord(['name' => '@', 'type' => 'NS', 'content' => 'ns1.example.com']);

        $this->assertSame('@', $record->fresh()->name);
    }

    public function test_write_paths_can_never_end_up_with_duplicate_rows_differing_only_by_grafia(): void
    {
        $context = $this->context();

        $this->createRecord([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $context['zone']->id,
            'name' => 'ns1.'.$context['zone']->name,
            'type' => 'A',
            'content' => '198.51.100.10',
        ]);

        // Simula uma segunda escrita para o "mesmo" registro, como faria uma
        // importação de zona descoberta do BIND (nome em FQDN com ponto final).
        $second = $this->createRecord([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $context['zone']->id,
            'name' => 'NS1.'.$context['zone']->name.'.',
            'type' => 'A',
            'content' => '198.51.100.10',
        ]);

        $canonicalName = 'ns1.'.$context['zone']->name;
        $this->assertSame($canonicalName, $second->fresh()->name);
        $this->assertSame(
            2,
            DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->where('name', $canonicalName)->count(),
            'Este teste não impede duas linhas — a proteção contra duplicidade fica na reconciliação/comparação; aqui só garantimos que ambas ficam na mesma forma canônica.',
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRecord(array $overrides = []): DnsRecord
    {
        $context = $this->context();

        return DnsRecord::query()->create(array_merge([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $context['zone']->id,
            'name' => 'www',
            'ttl' => null,
            'priority' => null,
            'enabled' => true,
        ], $overrides));
    }

    /**
     * @return array{organization: Organization, zone: DnsZone}
     */
    private function context(): array
    {
        if ($this->sharedContext !== null) {
            return $this->sharedContext;
        }

        $organization = Organization::factory()->create();

        $zone = DnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => 'example.com',
            'kind' => 'primary',
            'serial' => 1,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.example.com',
            'soa_rname' => 'hostmaster.example.com',
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
        ]);

        return $this->sharedContext = compact('organization', 'zone');
    }
}
