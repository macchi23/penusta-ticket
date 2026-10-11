<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sale_phases ADD CONSTRAINT sale_phases_method_check CHECK (method IN ('first_come'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_phases DROP CONSTRAINT sale_phases_method_check');
    }
};
