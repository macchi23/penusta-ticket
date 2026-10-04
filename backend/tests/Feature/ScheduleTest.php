<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_expire_holds_is_scheduled(): void
    {
        $events = app(Schedule::class)->events();
        $expireHoldsEvents = array_filter(
            $events,
            fn ($e) => Event::normalizeCommand($e->command) === 'php artisan tickets:expire-holds'
        );

        $this->assertCount(1, $expireHoldsEvents);
        $event = reset($expireHoldsEvents);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->withoutOverlapping);
        // onFailure(Stringable) で出力が捕捉されていないと、失敗理由が /dev/null に捨てられる
        $this->assertNotSame($event->getDefaultOutput(), $event->output);
    }

    public function test_export_events_is_scheduled(): void
    {
        $events = app(Schedule::class)->events();
        $exportEvents = array_filter(
            $events,
            fn ($e) => Event::normalizeCommand($e->command) === 'php artisan events:export'
        );

        $this->assertCount(1, $exportEvents);
        $event = reset($exportEvents);
        $this->assertSame('30 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
        $this->assertNotSame($event->getDefaultOutput(), $event->output);
    }

    public function test_export_events_is_due_at_00_30(): void
    {
        $events = app(Schedule::class)->events();
        $exportEvents = array_filter(
            $events,
            fn ($e) => Event::normalizeCommand($e->command) === 'php artisan events:export'
        );

        $event = reset($exportEvents);

        $this->travelTo(Carbon::parse('2026-10-05 00:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));

        $this->travelTo(Carbon::parse('2026-10-05 00:29:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));

        $this->travelTo(Carbon::parse('2026-10-05 01:30:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }
}
