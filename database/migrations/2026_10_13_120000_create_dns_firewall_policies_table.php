<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_firewall_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->unique()->constrained('dns_servers')->cascadeOnDelete();
            $table->jsonb('administrative_acls')->default('[]');
            $table->jsonb('administrative_ports')->default('[]');
            $table->jsonb('public_ports')->default('[]');
            $table->char('desired_hash', 64);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_firewall_policies');
    }
};
