<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id');
            $table->string('device')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
