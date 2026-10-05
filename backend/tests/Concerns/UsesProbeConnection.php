<?php

namespace Tests\Concerns;

use App\Models\Block;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * 別接続（probe）で行ロックを握る / コミットするテスト用。使うクラスは DatabaseTruncation と $match（FootballMatch）を持つこと。
 * tearDown で probe を rollback → purge し、全テーブルを空にして後続の RefreshDatabase 系テストへデータを残さない。
 */
trait UsesProbeConnection
{
    private ?Connection $probe = null;

    protected function tearDown(): void
    {
        if ($this->probe !== null) {
            if ($this->probe->transactionLevel() > 0) {
                $this->probe->rollBack();
            }
            DB::purge('probe');
            $this->probe = null;
        }
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function probeConnection(): Connection
    {
        if ($this->probe === null) {
            config(['database.connections.probe' => config('database.connections.pgsql')]);
            $this->probe = DB::connection('probe');
        }

        return $this->probe;
    }

    /** 別接続でトランザクションを開き、allocation 行を指定モードで握ったままにする。 */
    private function probeLock(Block $block, string $mode): void
    {
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select(
            "select id from match_block_allocations where match_id = ? and block_id = ? {$mode}",
            [$this->match->id, $block->id],
        );
    }
}
