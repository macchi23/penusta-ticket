<?php

namespace App\Support\Export;

use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * 行を gzip JSON Lines の 1 キーに書き出し、同じ prefix の他キーを消す。
 * 書き込みの前に失敗した場合は既存キーに触れない。0 行なら既存キーを全部消す
 * （$writeEmpty = true なら 0 行でも空の gzip を $key に書き、他のキーを消す）。
 */
class GzipJsonlExporter
{
    /**
     * @param  iterable<array<string, mixed>>  $rows  1 行 1 レコード。id があれば失敗メッセージに使う
     * @param  string  $label  失敗メッセージの先頭に付ける識別子（例: dt=2026-08-23）
     * @return array{rows: int, deleted: int}
     *
     * @throws ExportFailedException
     */
    public function export(Filesystem $disk, string $dir, string $key, iterable $rows, string $label, bool $writeEmpty = false): array
    {
        $count = 0;
        $stream = fopen('php://temp', 'w+b');
        $filter = stream_filter_append($stream, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31]);
        $failure = $filter === false ? 'gzip フィルタを設定できませんでした' : null;

        try {
            if ($failure === null) {
                foreach ($rows as $row) {
                    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        $failure = '一時ストリームへの書き込みに失敗しました (id='.($row['id'] ?? '').')';
                        break;
                    }
                    $count++;
                }
            }
            if ($failure === null && stream_filter_remove($filter) === false) {
                $failure = 'gzip フィルタの終了に失敗しました';
            }
        } catch (Throwable $e) {
            $failure = 'エンコードに失敗しました: '.$e->getMessage();
        }

        $write = false;
        try {
            if ($failure !== null) {
                throw new ExportFailedException("{$label} {$failure}");
            }
            $write = $count > 0 || $writeEmpty;
            if ($write) {
                rewind($stream);
                if ($disk->writeStream($key, $stream) === false) {
                    throw new ExportFailedException("書き込みに失敗しました: {$key}");
                }
            }
        } finally {
            fclose($stream);
        }

        try {
            $existingKeys = $disk->allFiles($dir);
        } catch (Throwable $e) {
            throw new ExportFailedException("{$label} key={$dir}/ 一覧の取得に失敗しました: ".$e->getMessage());
        }

        $deleted = 0;
        foreach ($existingKeys as $existing) {
            if ($write && $existing === $key) {
                continue;
            }
            if ($disk->delete($existing) === false) {
                throw new ExportFailedException("削除に失敗しました: {$existing}");
            }
            $deleted++;
        }

        return ['rows' => $count, 'deleted' => $deleted];
    }
}
