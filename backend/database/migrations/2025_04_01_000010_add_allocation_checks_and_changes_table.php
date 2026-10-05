<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VALUES = "('home','away','buffer','closed')";

    public function up(): void
    {
        DB::transaction(function () {
            DB::statement('ALTER TABLE match_block_allocations ADD CONSTRAINT match_block_allocations_allocation_check CHECK (allocation IN '.self::VALUES.')');
            DB::statement('ALTER TABLE allocation_template_entries ADD CONSTRAINT allocation_template_entries_allocation_check CHECK (allocation IN '.self::VALUES.')');

            Schema::create('match_block_allocation_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();
                $table->foreignId('block_id')->constrained()->restrictOnDelete();
                $table->string('from_allocation');
                $table->string('to_allocation');
                $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
                $table->uuid('change_set_id');
                $table->timestampTz('changed_at');

                $table->index(['match_id', 'changed_at']);
                $table->index('change_set_id');
            });
            DB::statement('ALTER TABLE match_block_allocation_changes ADD CONSTRAINT match_block_allocation_changes_from_check CHECK (from_allocation IN '.self::VALUES.')');
            DB::statement('ALTER TABLE match_block_allocation_changes ADD CONSTRAINT match_block_allocation_changes_to_check CHECK (to_allocation IN '.self::VALUES.')');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::dropIfExists('match_block_allocation_changes');
            DB::statement('ALTER TABLE allocation_template_entries DROP CONSTRAINT allocation_template_entries_allocation_check');
            DB::statement('ALTER TABLE match_block_allocations DROP CONSTRAINT match_block_allocations_allocation_check');
        });
    }
};
