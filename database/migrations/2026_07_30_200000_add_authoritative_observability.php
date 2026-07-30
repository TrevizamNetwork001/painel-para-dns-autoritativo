<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->json('authoritative_runtime')->nullable();
            $table->timestampTz('authoritative_observed_at')->nullable();
            $table->unsignedBigInteger('authoritative_sequence')->nullable();
        });

        Schema::create('dns_authoritative_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('expected_serial');
            $table->unsignedBigInteger('observed_serial')->nullable();
            $table->string('status', 32)->default('unknown');
            $table->string('zone_role', 16);
            $table->string('zone_state', 80)->nullable();
            $table->string('primary_address', 45)->nullable();
            $table->string('transfer_status', 32)->nullable();
            $table->timestampTz('last_refresh_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_transfer_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->string('source', 40);
            $table->uuid('event_id');
            $table->unsignedBigInteger('sequence');
            $table->timestampTz('agent_observed_at');
            $table->timestampTz('server_received_at');
            $table->timestampsTz();

            $table->unique(['dns_server_id', 'dns_zone_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('dns_authoritative_observation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_agent_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->unsignedBigInteger('sequence');
            $table->string('payload_hash', 64);
            $table->timestampsTz();

            $table->unique(['dns_agent_id', 'event_id']);
            $table->unique(['dns_agent_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_authoritative_observation_events');
        Schema::dropIfExists('dns_authoritative_observations');

        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->dropColumn([
                'authoritative_runtime',
                'authoritative_observed_at',
                'authoritative_sequence',
            ]);
        });
    }
};
