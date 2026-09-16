<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE dns_zones ALTER COLUMN default_ttl SET DEFAULT 300');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dns_zones ALTER COLUMN default_ttl SET DEFAULT 3600');
    }
};
