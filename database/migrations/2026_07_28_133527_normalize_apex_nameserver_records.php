<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A normalização é feita em PHP para manter compatibilidade
         * entre PostgreSQL, usado pela aplicação, e SQLite, usado
         * pela suíte de testes.
         */
        DB::table('dns_records')
            ->join(
                'dns_zones',
                'dns_zones.id',
                '=',
                'dns_records.dns_zone_id',
            )
            ->where('dns_records.type', 'NS')
            ->whereColumn(
                'dns_records.organization_id',
                'dns_zones.organization_id',
            )
            ->select([
                'dns_records.id as record_id',
                'dns_records.name as record_name',
                'dns_zones.name as zone_name',
            ])
            ->orderBy('dns_records.id')
            ->chunkById(
                200,
                function ($records): void {
                    foreach ($records as $record) {
                        $recordName = strtolower(
                            rtrim(trim((string) $record->record_name), '.'),
                        );

                        $zoneName = strtolower(
                            rtrim(trim((string) $record->zone_name), '.'),
                        );

                        if ($recordName !== $zoneName) {
                            continue;
                        }

                        DB::table('dns_records')
                            ->where(
                                'id',
                                (int) $record->record_id,
                            )
                            ->update([
                                'name' => '@',
                                'updated_at' => now(),
                            ]);
                    }
                },
                'dns_records.id',
                'record_id',
            );
    }

    public function down(): void
    {
        /*
         * Não restauramos o FQDN anterior porque "@" é a forma
         * canônica do apex. Além disso, o valor anterior não pode
         * ser reconstruído de maneira inequívoca após a normalização.
         */
    }
};
