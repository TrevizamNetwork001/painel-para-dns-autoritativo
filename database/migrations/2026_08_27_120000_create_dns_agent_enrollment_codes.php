<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_agent_enrollment_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->constrained('dns_servers')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'dns_server_id', 'expires_at'], 'agent_enrollment_codes_lookup');
        });

        Schema::table('dns_agent_install_requests', function (Blueprint $table): void {
            $table->foreignId('enrollment_code_id')
                ->nullable()->after('dns_server_id')
                ->constrained('dns_agent_enrollment_codes')->nullOnDelete();
            $table->json('review_warnings')->nullable()->after('enrollment_code_id');
            $table->string('enrollment_source', 20)->default('legacy')->after('review_warnings');
        });
    }

    public function down(): void
    {
        Schema::table('dns_agent_install_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('enrollment_code_id');
            $table->dropColumn(['review_warnings', 'enrollment_source']);
        });
        Schema::dropIfExists('dns_agent_enrollment_codes');
    }
};
