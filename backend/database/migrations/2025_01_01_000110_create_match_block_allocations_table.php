<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_block_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->string('allocation');
            $table->timestamps();

            $table->unique(['match_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_block_allocations');
    }
};
