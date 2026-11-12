<?php

use App\Console\Commands\LapseGraceCommand;
use App\Console\Commands\NotifyTrialsCommand;
use App\Console\Commands\ResetDemoCommand;
use App\Console\Commands\RetentionSweepCommand;
use App\Console\Commands\SweepHoldsCommand;
use App\Console\Commands\SweepSignupsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The expired-hold sweeper. Every minute, one queued job per tenant with a schema.
 *
 * Every minute because the shortest hold is a fifteen-minute checkout and the longest wait a
 * customer should have for their own abandoned basket to disappear from somebody else's
 * availability is a minute; correctness never waits on it at all, since an expired hold is free
 * by time comparison inside every query.
 *
 * No overlap guard here and none wanted. The singleton is the transaction-scoped advisory lock
 * the sweep itself takes, per tenant, which is the guard that works across the several workers a
 * queue has; a scheduler-side mutex would only stop this one process from dispatching twice.
 */
Schedule::command(SweepHoldsCommand::class)->everyMinute();

/*
 * Grace running out. Every hour: the window is counted in days, so an hour's lateness at its end
 * is the whole of the error, and the gate never compares a date to make up for it. One statement
 * lapses every expired window, so two schedulers overlapping would each lapse what the other had
 * not.
 */
Schedule::command(LapseGraceCommand::class)->hourly();

/*
 * Abandoned signups. Every hour: a slug is freed 24 hours after reserve() created its row, when
 * the row is still provisioning and names no subscription, so an hour's lateness is the whole of
 * the error. Each row is given back under its own lock, so two schedulers overlapping delete
 * each row once.
 */
Schedule::command(SweepSignupsCommand::class)->hourly();

/*
 * The trial's two notices. Every hour: "three days left" falls due at 72 hours and "ends today"
 * at 24, so an hour's lateness is the whole of the error. Each notice is claimed by the
 * statement that stamps it, so two schedulers overlapping send each once.
 */
Schedule::command(NotifyTrialsCommand::class)->hourly();

/*
 * The demo tenant's rebuild. Nightly at 04:00 on the demo's own clock, Athens, the quietest
 * hour of its day: the demo is dropped and built again from nothing, a week of sample
 * bookings relative to the new day, and a request reaching it meanwhile answers as for a
 * provisioning row. Without overlapping, since two rebuilds at once would race for one
 * schema; the mutex is the scheduler's, which is all this one command needs.
 */
Schedule::command(ResetDemoCommand::class)->dailyAt('04:00')->timezone('Europe/Athens')->withoutOverlapping();

/*
 * The retention sweep. Nightly at 04:30 Athens, half an hour after the demo's rebuild starts:
 * every line of the retention table, one statement each in its own transaction. Daily because
 * every period is counted in days or months, so a day's lateness at its end is the whole of the
 * error. Without overlapping, so a sweep still running when the next falls due makes that one
 * stand down rather than run beside it; the mutex is the scheduler's, as the reset's is.
 */
Schedule::command(RetentionSweepCommand::class)->dailyAt('04:30')->timezone('Europe/Athens')->withoutOverlapping();
