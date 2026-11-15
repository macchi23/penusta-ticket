<?php

namespace App\Console\Commands;

use App\Support\Export\ExportFailedException;
use App\Support\Export\GzipJsonlExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportTables extends Command
{
    protected $signature = 'tables:export {--table=* : 表名（複数指定可。省略時は全表）}';

    protected $description = '業務テーブルの全量スナップショットを gzip JSON Lines で s3 ディスクへ出力する';

    private const DISK = 's3';

    private const PREFIX = 'raw';

    /**
     * 書き出す列のホワイトリスト（表 => 列 => 型）。SELECT * はしない。
     * 型: int / str / bool / date / ts（timestamptz・timestamp を UTC の Zulu 文字列で出す）/ json。
     * 意図して外している列: tickets.qr_uuid（入場用の値）、blocks.geometry（描画用で大きい）。
     * users / memberships / season_pass_seats / resale_* などは対象外。
     */
    private const TABLES = [
        'seasons' => [
            'id' => 'int', 'name' => 'str', 'year' => 'int', 'format' => 'str',
            'starts_on' => 'date', 'ends_on' => 'date',
        ],
        'teams' => [
            'id' => 'int', 'name' => 'str', 'short_name' => 'str', 'color' => 'str', 'is_home_club' => 'bool',
        ],
        'matches' => [
            'id' => 'int', 'season_id' => 'int', 'home_team_id' => 'int', 'away_team_id' => 'int',
            'stadium_id' => 'int', 'kickoff_at' => 'ts', 'status' => 'str', 'section_label' => 'str',
            'attributes' => 'json',
        ],
        'league_standings' => [
            'id' => 'int', 'season_id' => 'int', 'round' => 'int', 'team_id' => 'int', 'position' => 'int',
            'played' => 'int', 'won' => 'int', 'drawn' => 'int', 'lost' => 'int',
            'goals_for' => 'int', 'goals_against' => 'int', 'points' => 'int', 'form' => 'str',
        ],
        'league_matches' => [
            'id' => 'int', 'season_id' => 'int', 'round' => 'int', 'kickoff_at' => 'ts',
            'home_team_id' => 'int', 'away_team_id' => 'int', 'home_score' => 'int', 'away_score' => 'int',
            'match_id' => 'int',
        ],
        'stands' => [
            'id' => 'int', 'stadium_id' => 'int', 'name' => 'str', 'code' => 'str',
        ],
        'seat_categories' => [
            'id' => 'int', 'stadium_id' => 'int', 'name' => 'str', 'display_order' => 'int',
        ],
        'blocks' => [
            'id' => 'int', 'stand_id' => 'int', 'seat_category_id' => 'int', 'code' => 'str',
            'seating_type' => 'str', 'capacity' => 'int',
        ],
        'match_block_allocations' => [
            'id' => 'int', 'match_id' => 'int', 'block_id' => 'int', 'allocation' => 'str',
        ],
        'ticket_types' => [
            'id' => 'int', 'name' => 'str', 'code' => 'str', 'display_order' => 'int',
        ],
        'match_prices' => [
            'id' => 'int', 'match_id' => 'int', 'seat_category_id' => 'int', 'ticket_type_id' => 'int',
            'price' => 'int',
        ],
        'sale_phases' => [
            'id' => 'int', 'match_id' => 'int', 'kind' => 'str', 'method' => 'str', 'eligibility' => 'str',
            'starts_at' => 'ts', 'ends_at' => 'ts',
        ],
        'orders' => [
            'id' => 'int', 'user_id' => 'int', 'match_id' => 'int', 'status' => 'str', 'total' => 'int',
            'channel' => 'str', 'ordered_at' => 'ts', 'expires_at' => 'ts', 'sale_phase_id' => 'int',
            'kind' => 'str', 'created_at' => 'ts', 'updated_at' => 'ts',
        ],
        'order_items' => [
            'id' => 'int', 'order_id' => 'int', 'ticket_id' => 'int', 'ticket_type_id' => 'int', 'price' => 'int',
        ],
        'tickets' => [
            'id' => 'int', 'match_id' => 'int', 'block_id' => 'int', 'seat_id' => 'int', 'status' => 'str',
            'expires_at' => 'ts', 'season_pass_seat_id' => 'int', 'created_at' => 'ts', 'updated_at' => 'ts',
        ],
    ];

    /**
     * json 列ごとの出力を許可するキー（表.列 => キー）。ここに無い json 列は {} で出す。
     * 将来 jsonb にキーが増えても、ここへ足さない限り出さない。
     */
    private const JSON_KEYS = [
        'matches.attributes' => [
            'weather', 'temperature', 'day_type', 'is_holiday', 'opponent_popularity',
            'promo', 'round', 'round_ratio', 'unsold',
        ],
    ];

    public function handle(GzipJsonlExporter $exporter): int
    {
        $requested = $this->option('table');
        $tables = $requested === [] ? array_keys(self::TABLES) : array_values(array_unique($requested));

        $unknown = array_values(array_filter($tables, fn ($t) => ! is_string($t) || ! isset(self::TABLES[$t])));
        if ($unknown !== []) {
            $this->error('未知の表名です: '.implode(', ', array_map('strval', $unknown)));

            return self::FAILURE;
        }

        // 全表を同じ時点のスナップショットで読む。すでにトランザクション内（テスト等）なら分離レベルは変えられないのでそのまま走らせる
        $failed = DB::transactionLevel() === 0
            ? DB::transaction(function () use ($exporter, $tables) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

                return $this->exportAll($exporter, $tables);
            })
            : $this->exportAll($exporter, $tables);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     * @return bool 1 表でも失敗したら true
     */
    private function exportAll(GzipJsonlExporter $exporter, array $tables): bool
    {
        $failed = false;
        foreach ($tables as $table) {
            // 表ごとにセーブポイントを切る。読み出しの DB エラーで外側のトランザクションが中止状態になり、残りの表が止まるのを防ぐ
            try {
                DB::transaction(fn () => $this->exportTable($exporter, $table));
            } catch (ExportFailedException $e) {
                $this->error($e->getMessage());
                $failed = true;
            }
        }

        return $failed;
    }

    private function exportTable(GzipJsonlExporter $exporter, string $table): void
    {
        $disk = Storage::disk(self::DISK);
        $dir = self::PREFIX."/{$table}";
        $key = "{$dir}/part-0000.jsonl.gz";
        $columns = self::TABLES[$table];

        $query = DB::table($table)->select(array_keys($columns));
        $rows = (function () use ($query, $table, $columns) {
            foreach ($query->lazyById(1000) as $record) {
                yield $this->toRow($record, $table, $columns);
            }
        })();

        // 0 件でも空の gzip を書く（DuckDB の read_json はファイルが無いと失敗する）
        $result = $exporter->export($disk, $dir, $key, $rows, "table={$table}", writeEmpty: true);

        $this->line("table={$table} rows={$result['rows']} key={$key} deleted={$result['deleted']}");
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function toRow(object $record, string $table, array $columns): array
    {
        $row = [];
        foreach ($columns as $name => $type) {
            $row[$name] = $this->convert($record->{$name}, $type, "{$table}.{$name}");
        }

        return $row;
    }

    private function convert(mixed $value, string $type, string $path): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool' => (bool) $value,
            'date' => substr((string) $value, 0, 10),
            // timestamp（タイムゾーン無し）は UTC で保存する前提。timestamptz はオフセット付きで返る
            'ts' => CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601ZuluString(),
            'json' => $this->decodeJson($value, self::JSON_KEYS[$path] ?? []),
            default => (string) $value,
        };
    }

    /**
     * 許可キーだけを持つオブジェクトにする（無いキーは足さない。空なら {}）。
     * 値はスカラーか null だけを出す。オブジェクト・配列の値はキーごと落とす。
     *
     * @param  list<string>  $allowedKeys
     */
    private function decodeJson(mixed $value, array $allowedKeys): \stdClass
    {
        $decoded = is_string($value) ? json_decode($value) : $value;
        $out = new \stdClass;
        if ($decoded instanceof \stdClass) {
            foreach ($allowedKeys as $key) {
                if (! property_exists($decoded, $key)) {
                    continue;
                }
                $item = $decoded->{$key};
                if (is_scalar($item) || $item === null) {
                    $out->{$key} = $item;
                }
            }
        }

        return $out;
    }
}
