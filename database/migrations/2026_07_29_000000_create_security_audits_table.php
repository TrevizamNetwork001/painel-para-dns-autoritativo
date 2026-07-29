<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 80);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('email_hash', 64)->nullable();
            $table->string('actor', 80);
            $table->string('source', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('result', 20);
            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audits');
    }
};
