<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ScheduleContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

/**
 * Re-register the console schedule at a frozen time and return the event for the
 * given command. Freezing before boot matters because `between()` captures the
 * current time when the schedule is defined, not when the filter runs.
 */
function scheduledEventAt(string $command, string $time): Event
{
    Carbon::setTestNow($time);

    Schedule::clearResolvedInstances();
    app()->forgetInstance(ScheduleContract::class);

    require base_path('routes/console.php');

    $event = collect(Schedule::events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', "'artisan' {$command}"));

    expect($event)->not->toBeNull("No scheduled event found for [{$command}].");

    return $event;
}

afterEach(fn () => Carbon::setTestNow());

test('the jira sync runs every five minutes on weekdays monday to thursday without overlapping', function () {
    $event = scheduledEventAt('jira:sync', '2026-01-01 12:00:00');

    expect($event->expression)->toBe('*/5 * * * 1,2,3,4')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('the jira sync only runs between 8 and 16', function () {
    expect(scheduledEventAt('jira:sync', '2026-01-01 07:55:00')->filtersPass(app()))->toBeFalse();
    expect(scheduledEventAt('jira:sync', '2026-01-01 12:00:00')->filtersPass(app()))->toBeTrue();
    expect(scheduledEventAt('jira:sync', '2026-01-01 16:05:00')->filtersPass(app()))->toBeFalse();
});

test('the jira sync only runs monday through thursday', function () {
    // Monday through Thursday are due.
    expect(scheduledEventAt('jira:sync', '2026-01-05 12:00:00')->isDue(app()))->toBeTrue() // Monday
        ->and(scheduledEventAt('jira:sync', '2026-01-06 12:00:00')->isDue(app()))->toBeTrue() // Tuesday
        ->and(scheduledEventAt('jira:sync', '2026-01-07 12:00:00')->isDue(app()))->toBeTrue() // Wednesday
        ->and(scheduledEventAt('jira:sync', '2026-01-01 12:00:00')->isDue(app()))->toBeTrue(); // Thursday

    // Friday through Sunday are not.
    expect(scheduledEventAt('jira:sync', '2026-01-02 12:00:00')->isDue(app()))->toBeFalse() // Friday
        ->and(scheduledEventAt('jira:sync', '2026-01-03 12:00:00')->isDue(app()))->toBeFalse() // Saturday
        ->and(scheduledEventAt('jira:sync', '2026-01-04 12:00:00')->isDue(app()))->toBeFalse(); // Sunday
});
