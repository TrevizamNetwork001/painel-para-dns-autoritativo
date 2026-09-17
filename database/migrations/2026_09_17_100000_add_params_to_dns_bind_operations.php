<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_bind_operations', function (Blueprint $table): void {
            $table->jsonb('params')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('dns_bind_operations', function (Blueprint $table): void {
            $table->dropColumn('params');
        });
    }
};
