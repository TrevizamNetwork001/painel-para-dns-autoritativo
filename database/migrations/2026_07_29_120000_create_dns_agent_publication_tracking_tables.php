<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_agent_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_zone_version_id')
                ->constrained('dns_zone_versions')
                ->cascadeOnDelete();
            $table->foreignId('dns_server_id')
                ->constrained('dns_servers')
                ->cascadeOnDelete();
            $table->foreignId('dns_agent_id')
                ->constrained('dns_agents')
                ->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('installed_version')->nullable();
            $table->string('artifact_checksum', 128)->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestampTz('last_apply_at')->nullable();
            $table->timestampTz('agent_reported_at')->nullable();
            $table->string('last_apply_error', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['dns_zone_version_id', 'dns_server_id', 'dns_agent_id'],
                'dns_agent_publications_destination_unique',
            );
            $table->index(
                ['dns_server_id', 'status'],
                'dns_agent_publications_server_status_index',
            );
            $table->index(
                ['dns_zone_version_id', 'status'],
                'dns_agent_publications_version_status_index',
            );
        });

        Schema::create('dns_agent_publication_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_agent_publication_id')
                ->constrained('dns_agent_publications')
                ->cascadeOnDelete();
            $table->foreignId('dns_agent_id')
                ->constrained('dns_agents')
                ->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('status', 20);
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['dns_agent_id', 'event_id'],
                'dns_agent_publication_events_agent_event_unique',
            );
            $table->index(
                ['dns_agent_publication_id', 'created_at'],
                'dns_agent_publication_events_publication_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_agent_publication_events');
        Schema::dropIfExists('dns_agent_publications');
    }
};
