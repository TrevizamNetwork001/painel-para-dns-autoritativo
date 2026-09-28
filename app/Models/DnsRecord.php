<?php

namespace App\Models;

use App\Support\DnsRecordCanonicalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    public const TYPES = [
        'A',
        'AAAA',
        'CNAME',
        'MX',
        'TXT',
        'CAA',
        'NS',
        'PTR',
    ];

    protected $fillable = [
        'organization_id',
        'dns_zone_id',
        'name',
        'type',
        'ttl',
        'priority',
        'content',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    protected static function booted(): void
    {
        // Ponto único de canonização: cobre criação manual, importação de zona
        // descoberta, sincronização de nameservers e qualquer outro caminho de
        // escrita, sem depender de cada chamador lembrar de normalizar.
        // Ver DnsRecordCanonicalizer e docs/KNOWN_ISSUES.md (DISC-001).
        static::saving(function (self $record): void {
            // PTR fica de fora de propósito: o nome (o "owner" derivado do IP,
            // ex. "10.2.0.192.in-addr.arpa.") e o conteúdo (o hostname apontado)
            // já são gravados com ponto final pelo assistente de reversos e pela
            // sincronização de PTR (ReverseZoneNameCalculator, DnsZonePtrSync) —
            // é a forma canônica ESTABELECIDA para esse tipo nesta aplicação, e
            // mexer nela quebraria o casamento exato que esses fluxos já fazem.
            $type = is_string($record->type) ? strtoupper($record->type) : null;

            if ($type === 'PTR') {
                return;
            }

            if (is_string($record->name)) {
                $record->name = DnsRecordCanonicalizer::name($record->name);
            }

            if (is_string($record->content) && $type !== null && in_array($type, DnsRecordCanonicalizer::NAME_CONTENT_TYPES, true)) {
                $record->content = DnsRecordCanonicalizer::name($record->content);
            }
        });
    }
}
