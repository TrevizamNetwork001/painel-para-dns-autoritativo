<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_audits', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('event')
                ->constrained()
                ->nullOnDelete();
            $table->string('reason', 80)->nullable()->after('result');
            $table->boolean('rate_limit_hit')
                ->default(false)
                ->after('reason');
            $table->index(['ip_address', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('security_audits', function (Blueprint $table): void {
            $table->dropIndex(['ip_address', 'event', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['reason', 'rate_limit_hit']);
        });
    }
};
