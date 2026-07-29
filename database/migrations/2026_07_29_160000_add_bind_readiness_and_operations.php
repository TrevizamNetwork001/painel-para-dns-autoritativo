<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->jsonb('bind_readiness')->nullable()->after('inventory');
            $table->timestampTz('bind_readiness_at')->nullable()->after('bind_readiness');
        });

        Schema::create('dns_bind_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->constrained('dns_servers')->cascadeOnDelete();
            $table->foreignId('dns_agent_id')->constrained('dns_agents')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('status', 20)->default('planned');
            $table->uuid('authorization_nonce')->unique();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestampsTz();

            $table->index(['dns_server_id', 'status']);
            $table->index(['dns_agent_id', 'status']);
        });

        Schema::create('dns_agent_bind_readiness_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_agent_id')->constrained('dns_agents')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['dns_agent_id', 'event_id']);
        });

        Schema::create('dns_bind_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_bind_operation_id')
                ->constrained('dns_bind_operations')
                ->cascadeOnDelete();
            $table->foreignId('dns_agent_id')->constrained('dns_agents')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['dns_agent_id', 'event_id']);
            $table->index(['dns_bind_operation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_bind_operation_events');
        Schema::dropIfExists('dns_agent_bind_readiness_events');
        Schema::dropIfExists('dns_bind_operations');

        Schema::table('dns_servers', function (Blueprint $table): void {
            $table->dropColumn(['bind_readiness', 'bind_readiness_at']);
        });
    }
};
