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
            // 売り切ったチケットは resold にして席の占有から外す（新しいチケットが同じ席を取る）
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
            DB::statement(
                "ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('held','paid','released','expired','resold'))"
            );
            DB::statement('DROP INDEX tickets_active_seat_unique');
            DB::statement(
                "CREATE UNIQUE INDEX tickets_active_seat_unique ON tickets (match_id, seat_id) WHERE seat_id IS NOT NULL AND status NOT IN ('released','expired','resold')"
            );

            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_kind_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_kind_check CHECK (kind IN ('purchase','season_pass','resale'))");

            Schema::create('resale_listings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
                $table->foreignId('seller_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
                $table->foreignId('block_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
                $table->integer('face_price');
                $table->integer('price');
                $table->integer('seller_fee');
                $table->integer('buyer_fee');
                $table->integer('payout');
                $table->string('status')->default('active');
                $table->timestampTz('closes_at');
                $table->foreignId('buyer_order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('new_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
                $table->timestamps();

                $table->index(['match_id', 'status']);
            });
            DB::statement('ALTER TABLE resale_listings ADD CONSTRAINT resale_listings_payout_check CHECK (payout >= 0)');
            DB::statement("ALTER TABLE resale_listings ADD CONSTRAINT resale_listings_status_check CHECK (status IN ('active','sold','cancelled','expired'))");
            // 同じチケットに active / sold の出品は 1 件だけ。二重出品の最終防衛線
            DB::statement("CREATE UNIQUE INDEX resale_listings_ticket_live_unique ON resale_listings (ticket_id) WHERE status IN ('active','sold')");
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // 売れた出品を巻き戻す: 新チケットと resale の注文を消し、元チケットを paid に戻す
            DB::statement('DELETE FROM tickets WHERE id IN (SELECT new_ticket_id FROM resale_listings WHERE new_ticket_id IS NOT NULL)');
            DB::statement("DELETE FROM orders WHERE kind = 'resale'");
            DB::statement("UPDATE tickets SET status = 'paid' WHERE status = 'resold'");

            Schema::dropIfExists('resale_listings');

            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_kind_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_kind_check CHECK (kind IN ('purchase','season_pass'))");

            DB::statement('DROP INDEX tickets_active_seat_unique');
            DB::statement(
                "CREATE UNIQUE INDEX tickets_active_seat_unique ON tickets (match_id, seat_id) WHERE seat_id IS NOT NULL AND status NOT IN ('released','expired')"
            );
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
            DB::statement(
                "ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('held','paid','released','expired'))"
            );
        });
    }
};
