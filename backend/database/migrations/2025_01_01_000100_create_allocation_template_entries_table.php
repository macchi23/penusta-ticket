<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_template_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->string('allocation');
            $table->timestamps();

            $table->unique(['allocation_template_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_template_entries');
    }
};
