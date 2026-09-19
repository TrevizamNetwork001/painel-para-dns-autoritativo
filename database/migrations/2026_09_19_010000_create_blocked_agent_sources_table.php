<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_agent_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->ipAddress('ip_address')->nullable()->unique();
            $table->string('reason', 40);
            $table->unsignedBigInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('firewall_synced_at')->nullable();
            $table->timestampsTz();
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_agent_sources');
    }
};
