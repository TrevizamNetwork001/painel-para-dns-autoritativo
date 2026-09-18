<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_bind_ignored_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dns_server_id')->constrained()->cascadeOnDelete();
            $table->string('zone_name');
            $table->string('note', 255)->nullable();
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dns_server_id', 'zone_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_bind_ignored_zones');
    }
};
