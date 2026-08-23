<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_category_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('seating_type');
            $table->integer('capacity');
            $table->jsonb('geometry');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
