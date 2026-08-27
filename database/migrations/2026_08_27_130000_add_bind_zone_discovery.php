<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_bind_discovered_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->constrained('dns_servers')->cascadeOnDelete();
            $table->foreignId('dns_agent_id')->constrained('dns_agents')->cascadeOnDelete();
            $table->foreignId('dns_bind_operation_id')
                ->constrained('dns_bind_operations')
                ->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('detected_type', 20)->nullable();
            $table->string('detected_syntax', 20)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->unsignedBigInteger('serial')->nullable();
            $table->unsignedInteger('node_count')->nullable();
            $table->boolean('dynamic')->default(false);
            $table->boolean('secure')->default(false);
            $table->string('file_owner', 80)->nullable();
            $table->string('file_group', 80)->nullable();
            $table->string('file_mode', 10)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestampTz('file_mtime')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('validation_status', 20)->nullable();
            $table->string('validation_message', 500)->nullable();
            $table->string('source_config_path', 500)->nullable();
            $table->string('comparison_state', 20)->default('new');
            $table->json('unsupported_record_types')->nullable();
            $table->jsonb('soa')->nullable();
            $table->jsonb('records')->nullable();
            $table->json('warnings')->nullable();
            $table->timestampsTz();

            $table->unique(['dns_bind_operation_id', 'name']);
            $table->index(['organization_id', 'name']);
        });

        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->string('origin', 20)->default('managed')->after('notes');
            $table->timestampTz('imported_at')->nullable()->after('origin');
            $table->foreignId('imported_by')->nullable()
                ->after('imported_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('import_source_id')->nullable()
                ->after('imported_by')
                ->constrained('dns_bind_discovered_zones')->nullOnDelete();
            $table->uuid('import_batch_id')->nullable()->after('import_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_source_id');
            $table->dropConstrainedForeignId('imported_by');
            $table->dropColumn(['origin', 'imported_at', 'import_batch_id']);
        });

        Schema::dropIfExists('dns_bind_discovered_zones');
    }
};
