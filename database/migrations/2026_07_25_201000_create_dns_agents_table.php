<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_agents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('dns_server_id')
                ->unique()
                ->constrained('dns_servers')
                ->cascadeOnDelete();

            $table->uuid('agent_uuid')->unique();
            $table->string('fingerprint', 255);
            $table->string('token_hash', 64);
            $table->string('reported_hostname')->nullable();
            $table->ipAddress('registered_ip')->nullable();
            $table->timestampTz('registered_at');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index([
                'organization_id',
                'revoked_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_agents');
    }
};
