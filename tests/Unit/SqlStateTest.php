<?php

use App\Infrastructure\Db\AbortedTransactionReused;
use App\Infrastructure\Db\BudgetExceeded;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\CardinalityViolation;
use App\Infrastructure\Db\ExclusionViolation;
use App\Infrastructure\Db\ProtocolHole;
use App\Infrastructure\Db\RetriesExhausted;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\UniqueViolation;

/*
 * The error table, as code: which SQLSTATEs the runner knows, which two retry, what each
 * surfaces as and how loudly. Detection is by code alone, so every synthetic error here carries
 * a message the framework's own detector would not recognise.
 */

it('reads the SQLSTATE from errorInfo through any wrapping, outermost first', function () {
    $query = sqlError('40P01');
    $wrapped = new RuntimeException('a domain wrapper', 0, $query);

    expect(SqlState::codeOf($query->getPrevious() ?? $query))->toBe('40P01')
        ->and(SqlState::codeOf($query))->toBe('40P01')
        ->and(SqlState::codeOf($wrapped))->toBe('40P01')
        ->and(SqlState::codeOf(new RuntimeException('deadlock detected')))->toBeNull()
        ->and(SqlState::codeOf(new PDOException('no state at all')))->toBeNull();
});

it('knows the eight states of the table and no other', function () {
    expect(array_map(fn (SqlState $state): string => $state->value, SqlState::cases()))
        ->toBe(['40001', '40P01', '55P03', '57014', '23P01', '23505', '21000', '25P02'])
        ->and(SqlState::of(sqlError('22012')))->toBeNull()
        ->and(SqlState::of(sqlError('40P01')))->toBe(SqlState::DeadlockDetected)
        ->and(SqlState::of(new RuntimeException('wrapped', 0, sqlError('55P03'))))->toBe(SqlState::LockNotAvailable);
});

it('retries exactly 40001 and 40P01', function () {
    $retried = array_values(array_filter(SqlState::cases(), fn (SqlState $state): bool => $state->retries()));

    expect($retried)->toBe([SqlState::SerializationFailure, SqlState::DeadlockDetected]);
});

it('maps {sqlstate} to {failure}', function (string $sqlstate, string $failure) {
    $cause = sqlError($sqlstate);
    $state = SqlState::from($sqlstate);

    $mapped = $state->failure($cause, 'acme', 3);

    expect($mapped::class)->toBe($failure)
        ->and($mapped->sqlstate)->toBe($state)
        ->and($mapped->tenant)->toBe('acme')
        ->and($mapped->getPrevious())->toBe($cause)
        ->and($mapped->getMessage())->toContain('acme');
})->with([
    ['55P03', Busy::class],
    ['57014', BudgetExceeded::class],
    ['23P01', ExclusionViolation::class],
    ['23505', UniqueViolation::class],
    ['21000', CardinalityViolation::class],
    ['25P02', AbortedTransactionReused::class],
    ['40001', RetriesExhausted::class],
    ['40P01', RetriesExhausted::class],
]);

it('maps every state to its own failure type', function () {
    $types = array_map(
        fn (SqlState $state): string => $state->failure(sqlError($state->value), 'acme', 1)::class,
        SqlState::cases(),
    );

    // The two retried states share one type, since both end the same way: out of attempts.
    expect(array_unique($types))->toHaveCount(7);
});

it('treats 23505 as 23P01: both are protocol holes at the alert level', function () {
    expect(SqlState::UniqueViolation->failure(sqlError('23505'), 'acme', 1))->toBeInstanceOf(ProtocolHole::class)
        ->and(SqlState::ExclusionViolation->failure(sqlError('23P01'), 'acme', 1))->toBeInstanceOf(ProtocolHole::class)
        ->and(SqlState::UniqueViolation->logLevel())->toBe('alert')
        ->and(SqlState::ExclusionViolation->logLevel())->toBe('alert');
});

it('says bug for {sqlstate}, which only code that continued after an error can raise', function (string $sqlstate) {
    expect(SqlState::from($sqlstate)->failure(sqlError($sqlstate), 'acme', 1)->getMessage())->toContain('bug');
})->with(['21000', '25P02']);

it('surfaces the retried states as exhausted, carrying the attempt count', function () {
    $failure = SqlState::DeadlockDetected->failure(sqlError('40P01'), 'acme', 3);

    expect($failure)->toBeInstanceOf(RetriesExhausted::class)
        ->and($failure instanceof RetriesExhausted ? $failure->attempts : null)->toBe(3)
        ->and($failure->getMessage())->toContain('3 attempts');
});

it('reports {sqlstate} at the {level} level', function (string $sqlstate, string $level) {
    expect(SqlState::from($sqlstate)->logLevel())->toBe($level);
})->with([
    ['40001', 'warning'],
    ['40P01', 'warning'],
    ['55P03', 'notice'],
    ['57014', 'critical'],
    ['23P01', 'alert'],
    ['23505', 'alert'],
    ['21000', 'error'],
    ['25P02', 'error'],
]);
