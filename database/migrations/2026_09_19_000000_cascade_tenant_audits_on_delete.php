<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_audit_logs', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        Schema::table('security_audits', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dns_audit_logs', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });

        Schema::table('security_audits', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }
};
