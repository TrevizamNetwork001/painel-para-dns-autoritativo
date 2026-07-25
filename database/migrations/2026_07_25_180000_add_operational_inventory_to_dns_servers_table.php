<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->string('operating_system', 80)
                ->nullable()
                ->after('environment');

            $table->string('operating_system_version', 80)
                ->nullable()
                ->after('operating_system');

            $table->string('bind_version', 80)
                ->nullable()
                ->after('operating_system_version');

            $table->string('agent_status', 30)
                ->default('not_installed')
                ->after('agent_version');

            $table->string('agent_fingerprint', 255)
                ->nullable()
                ->after('agent_status');

            $table->timestampTz('agent_registered_at')
                ->nullable()
                ->after('agent_fingerprint');

            $table->jsonb('inventory')
                ->nullable()
                ->after('capabilities');

            $table->index([
                'organization_id',
                'agent_status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->dropIndex([
                'organization_id',
                'agent_status',
            ]);

            $table->dropColumn([
                'operating_system',
                'operating_system_version',
                'bind_version',
                'agent_status',
                'agent_fingerprint',
                'agent_registered_at',
                'inventory',
            ]);
        });
    }
};
