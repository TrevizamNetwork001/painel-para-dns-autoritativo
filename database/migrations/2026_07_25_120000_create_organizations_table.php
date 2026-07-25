<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('status', 30)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['status', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
