<?php

namespace Tests\Feature;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileDuplicateDnsRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_duplicates_without_changing_anything(): void
    {
        $context = $this->context();
        $this->insertDirtyDuplicate($context);

        Artisan::call('dns-center:reconcile-duplicate-records');
        $output = Artisan::output();

        $this->assertStringContainsString('1 grupo(s) duplicado(s), 1 linha(s) a remover', $output);
        $this->assertStringContainsString('somente leitura', $output);
        $this->assertSame(2, DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->count());
        $this->assertDatabaseMissing('dns_audit_logs', ['action' => 'dns.record_duplicate_reconciled']);
    }

    public function test_reports_when_there_is_nothing_to_reconcile(): void
    {
        $context = $this->context();
        $context['zone']->records()->create([
            'organization_id' => $context['organization']->id,
            'name' => 'www',
            'type' => 'A',
            'content' => '198.51.100.10',
            'enabled' => true,
        ]);

        Artisan::call('dns-center:reconcile-duplicate-records');

        $this->assertStringContainsString('Nenhuma duplicata', Artisan::output());
    }

    public function test_apply_with_force_keeps_one_row_canonicalized_deletes_the_rest_and_audits(): void
    {
        $context = $this->context();
        $ids = $this->insertDirtyDuplicate($context);

        Artisan::call('dns-center:reconcile-duplicate-records', ['--apply' => true, '--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('1 linha(s) removida(s) em 1 zona(s)', $output);
        $this->assertStringContainsString('Nenhuma zona foi publicada', $output);

        $remaining = DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame($ids[0], $remaining->first()->id);
        $this->assertSame('ns1.'.$context['zone']->name, $remaining->first()->name);

        $this->assertDatabaseHas('dns_audit_logs', [
            'action' => 'dns.record_duplicate_reconciled',
            'domain' => $context['zone']->name,
        ]);
    }

    public function test_apply_without_force_requires_interactive_confirmation(): void
    {
        $context = $this->context();
        $this->insertDirtyDuplicate($context);

        $this->artisan('dns-center:reconcile-duplicate-records', ['--apply' => true])
            ->expectsConfirmation('Remover 1 linha(s) duplicada(s), preservando uma por grupo?', 'no')
            ->assertExitCode(1);

        $this->assertSame(2, DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->count());
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $context = $this->context();
        $this->insertDirtyDuplicate($context);

        Artisan::call('dns-center:reconcile-duplicate-records', ['--apply' => true, '--force' => true]);
        Artisan::call('dns-center:reconcile-duplicate-records', ['--apply' => true, '--force' => true]);

        $this->assertStringContainsString('Nenhuma duplicata', Artisan::output());
        $this->assertSame(1, DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->count());
    }

    public function test_organization_filter_isolates_the_cleanup(): void
    {
        $context = $this->context();
        $this->insertDirtyDuplicate($context);

        $other = $this->context('Outra Organização', 'outra.example.com');
        $otherIds = $this->insertDirtyDuplicate($other);

        Artisan::call('dns-center:reconcile-duplicate-records', [
            '--organization' => $context['organization']->id,
            '--apply' => true,
            '--force' => true,
        ]);

        $this->assertSame(1, DnsRecord::query()->where('dns_zone_id', $context['zone']->id)->count());
        $this->assertSame(2, DnsRecord::query()->where('dns_zone_id', $other['zone']->id)->count());
        $this->assertTrue(DnsRecord::query()->whereKey($otherIds)->count() === 2);
    }

    /**
     * @return array{organization: Organization, zone: DnsZone}
     */
    private function context(string $orgName = 'Empresa Teste', string $zoneName = 'example.com'): array
    {
        $organization = Organization::factory()->create(['name' => $orgName]);

        $zone = DnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => $zoneName,
            'kind' => 'primary',
            'serial' => 1,
            'default_ttl' => 3600,
            'soa_mname' => 'ns1.'.$zoneName,
            'soa_rname' => 'hostmaster.'.$zoneName,
            'soa_refresh' => 3600,
            'soa_retry' => 900,
            'soa_expire' => 1209600,
            'soa_minimum' => 300,
            'status' => 'draft',
            'version' => 1,
            'enabled' => true,
        ]);

        return compact('organization', 'zone');
    }

    /**
     * Insere, direto no banco (fora do Eloquent), duas linhas que só divergem
     * por grafia — como uma zona importada antes da canonização no modelo.
     *
     * @param  array{organization: Organization, zone: DnsZone}  $context
     * @return list<int> IDs inseridos, na ordem (o mais antigo primeiro)
     */
    private function insertDirtyDuplicate(array $context): array
    {
        $now = now();
        $zoneName = $context['zone']->name;

        $first = DB::table('dns_records')->insertGetId([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $context['zone']->id,
            'name' => 'ns1.'.$zoneName,
            'type' => 'A',
            'content' => '198.51.100.10',
            'ttl' => 3600,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $second = DB::table('dns_records')->insertGetId([
            'organization_id' => $context['organization']->id,
            'dns_zone_id' => $context['zone']->id,
            'name' => 'NS1.'.$zoneName.'.',
            'type' => 'A',
            'content' => '198.51.100.10',
            'ttl' => 3600,
            'enabled' => true,
            'created_at' => $now->copy()->addSecond(),
            'updated_at' => $now->copy()->addSecond(),
        ]);

        return [$first, $second];
    }
}
