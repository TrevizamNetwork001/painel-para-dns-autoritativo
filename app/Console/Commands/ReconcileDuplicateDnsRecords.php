<?php

namespace App\Console\Commands;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Support\DnsAuditLogger;
use App\Support\DnsRecordCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia registros que só divergem por grafia (maiúsculas, ponto final) —
 * DISC-001. Nunca aplica automaticamente: por padrão só lista; `--apply` exige
 * confirmação explícita (ou `--force` em execução não interativa).
 *
 * O que este comando NÃO faz, de propósito: publicar a zona, falar com o
 * agente ou o BIND. A limpeza aqui só afeta o banco do painel; publicar
 * continua um passo manual e separado (ver docs/KNOWN_ISSUES.md, DISC-001).
 */
class ReconcileDuplicateDnsRecords extends Command
{
    protected $signature = 'dns-center:reconcile-duplicate-records
        {--organization= : Restringe a uma organização (ID)}
        {--zone= : Restringe a uma zona (ID)}
        {--apply : Aplica a limpeza (sem isso, só lista o que seria feito)}
        {--force : Não pede confirmação (uso não interativo, com --apply)}';

    protected $description = 'Lista/remove registros DNS duplicados só por grafia (maiúsculas, ponto final) — DISC-001';

    public function handle(): int
    {
        $records = DnsRecord::query()
            ->when($this->option('organization'), fn ($query, $id) => $query->where('organization_id', $id))
            ->when($this->option('zone'), fn ($query, $id) => $query->where('dns_zone_id', $id))
            ->orderBy('dns_zone_id')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'dns_zone_id', 'name', 'type', 'content', 'priority', 'ttl']);

        $groups = $records
            ->groupBy(fn (DnsRecord $record): string => $record->dns_zone_id.'|'.DnsRecordCanonicalizer::comparisonKey(
                $record->type,
                $record->name,
                $record->content,
                $record->priority,
            ))
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('Nenhuma duplicata por grafia encontrada.');

            return self::SUCCESS;
        }

        $zoneNames = DnsZone::query()
            ->whereIn('id', $groups->flatten()->pluck('dns_zone_id')->unique())
            ->pluck('name', 'id');

        $this->table(
            ['Zona', 'Tipo', 'Nome', 'Conteúdo', 'IDs (mantém o 1º)'],
            $groups->map(function (Collection $group) use ($zoneNames): array {
                $first = $group->first();

                return [
                    $zoneNames[$first->dns_zone_id] ?? ('#'.$first->dns_zone_id),
                    $first->type,
                    $group->pluck('name')->unique()->implode(' / '),
                    $group->pluck('content')->unique()->implode(' / '),
                    $group->pluck('id')->implode(', '),
                ];
            })->values()->all(),
        );

        $duplicateRows = $groups->sum(fn (Collection $group) => $group->count() - 1);
        $this->line("{$groups->count()} grupo(s) duplicado(s), {$duplicateRows} linha(s) a remover.");

        if (! $this->option('apply')) {
            $this->comment('Modo somente leitura. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        if (
            ! $this->option('force')
            && ! $this->confirm("Remover {$duplicateRows} linha(s) duplicada(s), preservando uma por grupo?")
        ) {
            $this->warn('Operação cancelada.');

            return self::FAILURE;
        }

        $affectedZones = [];

        DB::transaction(function () use ($groups, $zoneNames, &$affectedZones): void {
            foreach ($groups as $group) {
                /** @var DnsRecord $keeper */
                $keeper = $group->first();
                $duplicates = $group->skip(1);

                // Regrava o sobrevivente (o hook do modelo canoniza name/content ao
                // salvar; se já estava canônico, isso não gera nenhuma alteração) e
                // apaga o resto do grupo.
                $keeper->save();
                DnsRecord::query()->whereIn('id', $duplicates->pluck('id'))->delete();

                DnsAuditLogger::record(
                    organizationId: $keeper->organization_id,
                    user: null,
                    action: 'dns.record_duplicate_reconciled',
                    domain: $zoneNames[$keeper->dns_zone_id] ?? null,
                    recordType: $keeper->type,
                    recordName: $keeper->name,
                    message: sprintf(
                        'Registro mantido #%d; removidos: %s (comando dns-center:reconcile-duplicate-records).',
                        $keeper->id,
                        $duplicates->pluck('id')->implode(', '),
                    ),
                );

                $affectedZones[$keeper->dns_zone_id] = $zoneNames[$keeper->dns_zone_id] ?? ('#'.$keeper->dns_zone_id);
            }
        });

        $this->info("Reconciliado. {$duplicateRows} linha(s) removida(s) em ".count($affectedZones).' zona(s).');
        $this->comment('Nenhuma zona foi publicada por este comando. Revise e publique as afetadas quando quiser:');

        foreach ($affectedZones as $name) {
            $this->line('  - '.$name);
        }

        return self::SUCCESS;
    }
}
