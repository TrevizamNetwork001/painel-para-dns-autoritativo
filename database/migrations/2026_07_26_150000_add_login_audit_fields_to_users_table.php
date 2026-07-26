<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_login_ip', 45)
                ->nullable()
                ->after('last_login_at');

            $table->text('last_login_user_agent')
                ->nullable()
                ->after('last_login_ip');

            $table->timestamp('last_logout_at')
                ->nullable()
                ->after('last_login_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'last_login_ip',
                'last_login_user_agent',
                'last_logout_at',
            ]);
        });
    }
};
