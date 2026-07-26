<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE dns_agents
                 DROP CONSTRAINT IF EXISTS
                 dns_agents_dns_server_id_unique'
            );

            DB::statement(
                'CREATE UNIQUE INDEX
                 dns_agents_one_active_per_server
                 ON dns_agents (dns_server_id)
                 WHERE revoked_at IS NULL'
            );

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table(
                'dns_agents',
                function (Blueprint $table): void {
                    $table->dropUnique([
                        'dns_server_id',
                    ]);
                },
            );

            DB::statement(
                'CREATE UNIQUE INDEX
                 dns_agents_one_active_per_server
                 ON dns_agents (dns_server_id)
                 WHERE revoked_at IS NULL'
            );

            return;
        }

        Schema::table(
            'dns_agents',
            function (Blueprint $table): void {
                $table->dropUnique([
                    'dns_server_id',
                ]);
            },
        );

        Schema::table(
            'dns_agents',
            function (Blueprint $table): void {
                $table->unique(
                    [
                        'dns_server_id',
                        'revoked_at',
                    ],
                    'dns_agents_server_revoked_unique',
                );
            },
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'DROP INDEX IF EXISTS
                 dns_agents_one_active_per_server'
            );

            DB::statement(
                'ALTER TABLE dns_agents
                 ADD CONSTRAINT
                 dns_agents_dns_server_id_unique
                 UNIQUE (dns_server_id)'
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'DROP INDEX IF EXISTS
                 dns_agents_one_active_per_server'
            );

            Schema::table(
                'dns_agents',
                function (Blueprint $table): void {
                    $table->unique(
                        'dns_server_id',
                        'dns_agents_dns_server_id_unique',
                    );
                },
            );

            return;
        }

        Schema::table(
            'dns_agents',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'dns_agents_server_revoked_unique'
                );

                $table->unique(
                    'dns_server_id',
                    'dns_agents_dns_server_id_unique',
                );
            },
        );
    }
};
