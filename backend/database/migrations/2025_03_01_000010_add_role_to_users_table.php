<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement("ALTER TABLE users ADD COLUMN role varchar(255) NOT NULL DEFAULT 'customer'");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer','admin'))");
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            DB::statement('ALTER TABLE users DROP COLUMN role');
        });
    }
};
