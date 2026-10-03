<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportEvents extends Command
{
    protected $signature = 'events:export {--date= : UTC 日付 YYYY-MM-DD} {--from=} {--to=}';

    protected $description = 'events を UTC 日付ごとに Hive パーティション形式の gzip JSON Lines で s3 ディスクへ出力する';

    private const DISK = 's3';

    private const PREFIX = 'raw/events';

    public function handle(): int
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        foreach (['--date' => $date, '--from' => $from, '--to' => $to] as $name => $value) {
            if ($value === null && $this->input->hasParameterOption($name)) {
                $this->error("{$name} には値が必要です");

                return self::FAILURE;
            }
        }

        if ($date !== null && ($from !== null || $to !== null)) {
            $this->error('--date と --from/--to は併用できません');

            return self::FAILURE;
        }
        if (($from === null) !== ($to === null)) {
            $this->error('--from と --to は両方指定してください');

            return self::FAILURE;
        }

        if ($from !== null) {
            $start = $this->parseDate($from);
            $end = $this->parseDate($to);
        } elseif ($date !== null) {
            $start = $end = $this->parseDate($date);
        } else {
            $start = $end = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        }

        if ($start === null || $end === null) {
            $this->error('日付は YYYY-MM-DD 形式で指定してください');

            return self::FAILURE;
        }
        if ($start->gt($end)) {
            $this->error('--from は --to 以前の日付を指定してください');

            return self::FAILURE;
        }

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (! $this->exportDay($day)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $parsed->format('Y-m-d') === $value ? $parsed : null;
    }

    private function exportDay(CarbonImmutable $day): bool
    {
        $disk = Storage::disk(self::DISK);
        $dt = $day->format('Y-m-d');
        $dir = self::PREFIX."/dt={$dt}";
        $key = "{$dir}/part-0000.jsonl.gz";
        $lower = $day->utc();
        $upper = $day->addDay()->utc();

        $query = ActivityEvent::query()
            ->where('occurred_at', '>=', $lower)
            ->where('occurred_at', '<', $upper);

        $rows = 0;
        $stream = fopen('php://temp', 'w+b');
        $filter = stream_filter_append($stream, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31]);
        $failure = $filter === false ? 'gzip フィルタを設定できませんでした' : null;

        try {
            if ($failure === null) {
                foreach ($query->lazyById(1000) as $event) {
                    $line = json_encode($this->toRow($event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        $failure = "一時ストリームへの書き込みに失敗しました (id={$event->id})";
                        break;
                    }
                    $rows++;
                }
            }
            if ($failure === null && stream_filter_remove($filter) === false) {
                $failure = 'gzip フィルタの終了に失敗しました';
            }
        } catch (Throwable $e) {
            $failure = 'エンコードに失敗しました: '.$e->getMessage();
        }

        try {
            if ($failure !== null) {
                $this->error("dt={$dt} {$failure}");

                return false;
            }
            if ($rows > 0) {
                rewind($stream);
                if ($disk->writeStream($key, $stream) === false) {
                    $this->error("書き込みに失敗しました: {$key}");

                    return false;
                }
            }
        } finally {
            fclose($stream);
        }

        $deleted = 0;
        try {
            $existingKeys = $disk->allFiles($dir);
        } catch (Throwable $e) {
            $this->error("dt={$dt} key={$dir}/ 一覧の取得に失敗しました: ".$e->getMessage());

            return false;
        }
        foreach ($existingKeys as $existing) {
            if ($rows > 0 && $existing === $key) {
                continue;
            }
            if ($disk->delete($existing) === false) {
                $this->error("削除に失敗しました: {$existing}");

                return false;
            }
            $deleted++;
        }

        $this->line($rows > 0
            ? "dt={$dt} rows={$rows} key={$key} deleted={$deleted}"
            : "dt={$dt} rows=0 deleted={$deleted}");

        return true;
    }

    /** @return array<string, mixed> */
    private function toRow(ActivityEvent $event): array
    {
        $raw = $event->getRawOriginal('payload');
        $payload = is_string($raw) ? json_decode($raw) : null;
        if ($payload === []) {
            $payload = new \stdClass;
        }

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'user_id' => $event->user_id,
            'session_id' => $event->session_id,
            'device' => $event->device,
            // 生の JSON を object として decode し、空 payload は {} で出す（array キャストの [] を避ける）
            'payload' => $payload,
            'occurred_at' => $event->occurred_at?->utc()->toIso8601ZuluString(),
            'created_at' => $event->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
