<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_agent_install_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('request_token_hash', 64);
            $table->uuid('agent_uuid')->unique();
            $table->string('fingerprint', 255);
            $table->string('reported_hostname', 255);
            $table->ipAddress('registered_ip')->nullable();
            $table->string('agent_version', 50)->nullable();
            $table->string('operating_system', 80)->nullable();
            $table->string('operating_system_version', 80)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('dns_server_id')
                ->nullable()
                ->constrained('dns_servers')
                ->cascadeOnDelete();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('agent_token')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['organization_id', 'dns_server_id', 'status'],
                'dns_agent_install_requests_queue',
            );
            $table->index(['status', 'expires_at']);
        });

        Schema::dropIfExists('dns_agent_enrollments');
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_agent_install_requests');

        Schema::create('dns_agent_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('dns_server_id')
                ->constrained('dns_servers')
                ->cascadeOnDelete();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(
                ['organization_id', 'dns_server_id', 'expires_at'],
                'dns_agent_enrollment_lookup',
            );
        });
    }
};
