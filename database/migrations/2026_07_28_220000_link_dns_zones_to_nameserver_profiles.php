<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->foreignId('dns_nameserver_profile_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('dns_nameserver_profiles')
                ->nullOnDelete();

            $table->index(
                [
                    'organization_id',
                    'dns_nameserver_profile_id',
                ],
                'dns_zones_org_ns_profile_index',
            );
        });

        /*
         * Migração conservadora:
         * zonas existentes recebem somente o perfil padrão habilitado
         * da mesma organização.
         *
         * Quando não existir perfil padrão, a zona permanece sem perfil
         * para que o operador faça a associação explicitamente.
         */
        DB::table('dns_zones')
            ->orderBy('id')
            ->eachById(function (object $zone): void {
                $profileId = DB::table('dns_nameserver_profiles')
                    ->where('organization_id', $zone->organization_id)
                    ->where('enabled', true)
                    ->where('is_default', true)
                    ->orderBy('id')
                    ->value('id');

                if ($profileId === null) {
                    return;
                }

                DB::table('dns_zones')
                    ->where('id', $zone->id)
                    ->update([
                        'dns_nameserver_profile_id' => $profileId,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->dropIndex('dns_zones_org_ns_profile_index');
            $table->dropConstrainedForeignId(
                'dns_nameserver_profile_id',
            );
        });
    }
};
