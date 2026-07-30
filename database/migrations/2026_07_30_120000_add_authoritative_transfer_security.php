<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_tsig_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('algorithm', 32)->default('hmac-sha256');
            $table->text('secret');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rotated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'enabled']);
        });

        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->foreignId('dns_tsig_key_id')
                ->nullable()
                ->after('dns_nameserver_profile_id')
                ->constrained('dns_tsig_keys')
                ->nullOnDelete();
        });

        Schema::table('dns_agent_publications', function (Blueprint $table): void {
            $table->unsignedBigInteger('reported_serial')->nullable();
            $table->timestampTz('serial_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dns_agent_publications', function (Blueprint $table): void {
            $table->dropColumn(['reported_serial', 'serial_confirmed_at']);
        });

        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dns_tsig_key_id');
        });

        Schema::dropIfExists('dns_tsig_keys');
    }
};
