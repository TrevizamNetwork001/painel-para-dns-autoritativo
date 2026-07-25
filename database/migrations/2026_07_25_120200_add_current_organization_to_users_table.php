<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->nullOnDelete();

            $table->boolean('is_platform_admin')
                ->default(false)
                ->after('password');

            $table->string('status', 30)
                ->default('active')
                ->after('is_platform_admin');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn([
                'is_platform_admin',
                'status',
                'last_login_at',
            ]);
        });
    }
};
