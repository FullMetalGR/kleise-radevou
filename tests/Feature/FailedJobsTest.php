<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

pest()->use(DatabaseTruncation::class);

/*
 * The store a failed job lands in, which config/queue.php names and which a failure handler
 * needs to exist: a job that throws inside a handler that cannot record it loses the failure
 * and the payload with it, and a queue that swallows its own failures is worse than no queue.
 *
 * Not wrapped in a transaction: the worker runs in this process but on its own connections, and
 * a row written inside a wrapping transaction would not be there for it to find.
 */

/**
 * A job that cannot succeed, tried once so the worker gives up on the first attempt rather than
 * putting it back for a retry the test would have to wait out.
 */
final class UnworkableJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('this job was never going to work');
    }
}

it('carries the failed_jobs table the queue configuration names, in the central schema', function () {
    expect(config('queue.failed.table'))->toBe('failed_jobs')
        ->and(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(liveColumns('public', 'failed_jobs'))->toBe(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
});

it('lands one row in it when a job under the database driver throws', function () {
    config(['queue.default' => 'database']);

    UnworkableJob::dispatch();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--no-interaction' => true]);

    $failed = DB::table('failed_jobs')->get();

    expect($failed)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);

    $row = $failed->first();

    assert($row instanceof stdClass && is_string($row->connection) && is_string($row->exception) && is_string($row->payload));

    expect($row->connection)->toBe('database')
        ->and($row->exception)->toContain('this job was never going to work')
        ->and($row->payload)->toContain(UnworkableJob::class);
});
