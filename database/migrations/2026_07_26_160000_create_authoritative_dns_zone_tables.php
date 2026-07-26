<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('kind', 20)->default('primary');
            $table->unsignedBigInteger('serial');
            $table->unsignedInteger('default_ttl')->default(3600);
            $table->string('soa_mname', 255);
            $table->string('soa_rname', 255);
            $table->unsignedInteger('soa_refresh')->default(3600);
            $table->unsignedInteger('soa_retry')->default(900);
            $table->unsignedInteger('soa_expire')->default(1209600);
            $table->unsignedInteger('soa_minimum')->default(300);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('dns_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('type', 12);
            $table->unsignedInteger('ttl')->nullable();
            $table->unsignedInteger('priority')->nullable();
            $table->text('content');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->index(
                ['organization_id', 'dns_zone_id', 'type'],
                'dns_records_zone_type_index'
            );
        });

        Schema::create('dns_server_zone', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_server_id')->constrained('dns_servers')->cascadeOnDelete();
            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestampsTz();

            $table->unique(['dns_server_id', 'dns_zone_id']);
            $table->index(['dns_zone_id', 'role']);
        });

        Schema::create('dns_zone_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('serial');
            $table->string('reason', 255);
            $table->json('snapshot');
            $table->timestampsTz();

            $table->unique(['dns_zone_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zone_versions');
        Schema::dropIfExists('dns_server_zone');
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
    }
};
