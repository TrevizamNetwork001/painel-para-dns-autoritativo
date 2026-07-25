<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_servers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('hostname', 255);

            $table->string('ipv4_address', 45)->nullable();
            $table->string('ipv6_address', 45)->nullable();

            $table->string('role', 30)->default('primary');
            $table->string('environment', 30)->default('production');

            $table->string('status', 30)->default('pending');
            $table->boolean('enabled')->default(true);

            $table->uuid('agent_uuid')->nullable()->unique();
            $table->string('agent_version', 60)->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            $table->jsonb('capabilities')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->unique([
                'organization_id',
                'hostname',
            ]);

            $table->index([
                'organization_id',
                'status',
            ]);

            $table->index([
                'organization_id',
                'role',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_servers');
    }
};
