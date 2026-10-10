<?php

namespace App\Support\DemoData;

use PDO;
use Pdo\Pgsql;
use RuntimeException;

/** PostgreSQL の COPY（テキスト形式）で書き込むヘルパー */
final class PgCopy
{
    /** 行の中で NULL を表す文字列（COPY の既定） */
    public const NULL = '\N';

    /** copyFromArray の $nullAs。拡張が SQL の E'' 文字列に埋め込むため、バックスラッシュを 1 段重ねて渡す（NULL は \N を指す） */
    private const NULL_AS_ARGUMENT = '\\\\N';

    /** COPY テキスト形式のエスケープ。バックスラッシュ・タブ・改行を値の外の区切りと区別する */
    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
    }

    /**
     * 1 行分の文字列にする（改行は付けない）。null は \N、bool は t / f
     *
     * @param  list<int|float|string|bool|null>  $values
     */
    public static function line(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = match (true) {
                $value === null => self::NULL,
                is_int($value) => (string) $value,
                is_string($value) => strpbrk($value, "\\\t\n\r") === false ? $value : self::escape($value),
                is_bool($value) => $value ? 't' : 'f',
                default => (string) $value,
            };
        }

        return implode("\t", $cells);
    }

    /** timestamp（タイムゾーン無し）列向け。UTC */
    public static function timestamp(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch);
    }

    /** timestamptz 列向け。UTC */
    public static function timestampTz(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch).'+00';
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $lines  line() の結果
     */
    public static function copy(PDO $pdo, string $table, array $columns, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $fields = implode(',', $columns);
        // PHP 8.4 は Pdo\Pgsql::copyFromArray、PDO::pgsqlCopyFromArray は 8.5 で非推奨
        $ok = $pdo instanceof Pgsql
            ? $pdo->copyFromArray($table, $lines, "\t", self::NULL_AS_ARGUMENT, $fields)
            : $pdo->pgsqlCopyFromArray($table, $lines, "\t", self::NULL_AS_ARGUMENT, $fields);

        if ($ok !== true) {
            throw new RuntimeException("COPY {$table} に失敗: ".json_encode($pdo->errorInfo()));
        }
    }
}
