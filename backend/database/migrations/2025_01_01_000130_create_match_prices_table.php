<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('seat_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->integer('price');
            $table->timestamps();

            $table->unique(['match_id', 'seat_category_id', 'ticket_type_id'], 'match_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_prices');
    }
};
