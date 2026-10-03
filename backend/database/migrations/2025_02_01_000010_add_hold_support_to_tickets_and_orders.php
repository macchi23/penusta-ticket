<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 解放・期限切れのチケットは同じ席を再度押さえられるよう、ユニーク対象から外す。
            // 述語に now() は使えないため、期限の判定はアプリ側で行う。
            DB::statement(
                "CREATE UNIQUE INDEX tickets_active_seat_unique ON tickets (match_id, seat_id) WHERE seat_id IS NOT NULL AND status NOT IN ('released','expired')"
            );
            DB::statement('DROP INDEX tickets_match_id_seat_id_unique');
            DB::statement(
                "ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('held','paid','released','expired'))"
            );

            DB::statement('ALTER TABLE orders ADD COLUMN expires_at timestamptz NULL');
            DB::statement('ALTER TABLE orders ALTER COLUMN ordered_at DROP NOT NULL');
            DB::statement('ALTER TABLE orders ALTER COLUMN total SET DEFAULT 0');
            DB::statement(
                "ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','paid','expired'))"
            );
            DB::statement(
                "CREATE UNIQUE INDEX orders_one_pending_per_user_match ON orders (user_id, match_id) WHERE status = 'pending'"
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('DROP INDEX orders_one_pending_per_user_match');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
            DB::statement('ALTER TABLE orders ALTER COLUMN total DROP DEFAULT');
            DB::statement('UPDATE orders SET ordered_at = created_at WHERE ordered_at IS NULL');
            DB::statement('ALTER TABLE orders ALTER COLUMN ordered_at SET NOT NULL');
            DB::statement('ALTER TABLE orders DROP COLUMN expires_at');

            DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_status_check');
            DB::statement(
                'CREATE UNIQUE INDEX tickets_match_id_seat_id_unique ON tickets (match_id, seat_id) WHERE seat_id IS NOT NULL'
            );
            DB::statement('DROP INDEX tickets_active_seat_unique');
        });
    }
};
