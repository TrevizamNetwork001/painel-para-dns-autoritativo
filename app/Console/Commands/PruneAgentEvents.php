<?php

namespace App\Console\Commands;

use App\Models\DnsAgentBindReadinessEvent;
use App\Models\DnsAuthoritativeObservationEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * As tabelas de "eventos" dos relatórios de observação e de prontidão guardam
 * só (agente, event_id, hash) para descartar reenvios do mesmo relatório. Cada
 * agente reporta de poucos em poucos minutos (~500 linhas/dia por tabela) e
 * nada as limpava. O reenvio de um relatório só acontece dentro de minutos ou
 * horas; a ordem das observações é controlada por `servers.authoritative_sequence`,
 * não por estas tabelas — então apagar o que é antigo é seguro.
 *
 * `dns_bind_operation_events` fica de fora de propósito: volume baixo e o agente
 * pode reenviar o relatório final de uma operação depois de uma queda longa.
 */
class PruneAgentEvents extends Command
{
    protected $signature = 'dns-center:prune-agent-events
        {--days=30 : Apaga eventos com mais de N dias (mínimo 7)}
        {--dry-run : Só mostra quantas linhas seriam apagadas}';

    protected $description = 'Remove eventos antigos de idempotência dos relatórios de observação e prontidão dos agentes';

    private const CHUNK = 5000;

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 7) {
            $this->error('Use no mínimo 7 dias: reenvios de relatório não podem ser confundidos com novos.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        foreach ([
            'observações' => DnsAuthoritativeObservationEvent::class,
            'prontidão' => DnsAgentBindReadinessEvent::class,
        ] as $label => $model) {
            $this->line(sprintf(
                '%s: %d evento(s) %s',
                $label,
                $dryRun ? $this->count($model, $cutoff) : $this->delete($model, $cutoff),
                $dryRun ? 'seriam removidos.' : 'removidos.',
            ));
        }

        return self::SUCCESS;
    }

    /** @param class-string<Model> $model */
    private function count(string $model, $cutoff): int
    {
        return $model::query()->where('created_at', '<', $cutoff)->count();
    }

    /** @param class-string<Model> $model */
    private function delete(string $model, $cutoff): int
    {
        $total = 0;

        do {
            $ids = $model::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += $model::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}
