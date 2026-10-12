<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            Schema::create('resale_holds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('listing_id')->constrained('resale_listings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
                $table->string('status');
                $table->timestampTz('expires_at');
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->timestamps();
            });
            DB::statement("ALTER TABLE resale_holds ADD CONSTRAINT resale_holds_status_check CHECK (status IN ('held','released','expired','purchased'))");
            DB::statement("ALTER TABLE resale_holds ADD CONSTRAINT resale_holds_order_check CHECK ((status = 'purchased') = (order_id IS NOT NULL))");
            // 1 出品に有効な仮押さえは 1 件、1 人 1 試合に有効な仮押さえは 1 件。二重押さえの最終防衛線
            DB::statement("CREATE UNIQUE INDEX resale_holds_listing_live_unique ON resale_holds (listing_id) WHERE status = 'held'");
            DB::statement("CREATE UNIQUE INDEX resale_holds_user_match_live_unique ON resale_holds (user_id, match_id) WHERE status = 'held'");
            // 押さえ直しのクールダウン判定（user × listing の履歴）用。上の索引は held だけなので効かない
            DB::statement('CREATE INDEX resale_holds_user_listing_index ON resale_holds (user_id, listing_id)');
            DB::statement("CREATE INDEX resale_holds_expires_at_index ON resale_holds (expires_at) WHERE status = 'held'");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resale_holds');
    }
};
