<?php

use App\Tenancy\SchemaSwitch;
use Illuminate\Support\Facades\DB;

/*
 * The one switch of the search path to a tenant schema for a piece of work, which the build and
 * tenant:migrate share: SET LOCAL inside the caller's open transaction, the closure's answer
 * handed back, and the path the transaction held before put back after it. Not wrapped in a
 * transaction, so the refusal outside one can be seen; the probe schema is made and dropped here
 * and holds nothing.
 */

beforeEach(function () {
    DB::statement('drop schema if exists switch_probe cascade');
    DB::statement('create schema switch_probe');
});

afterEach(function () {
    DB::statement('drop schema if exists switch_probe cascade');
});

it('points unqualified names at the schema for the closure, answers what it answers, and puts the path back after it', function () {
    $central = DB::scalar("select current_setting('search_path')");

    [$inside, $after] = DB::transaction(function (): array {
        $inside = SchemaSwitch::within(DB::connection(), 'switch_probe', fn (): array => [
            DB::scalar('select current_schema()'),
            DB::scalar("select current_setting('search_path')"),
        ]);

        return [$inside, DB::scalar("select current_setting('search_path')")];
    });

    expect($inside)->toBe(['switch_probe', 'switch_probe, public'])
        ->and($after)->toBe($central)
        ->and(DB::scalar("select current_setting('search_path')"))->toBe($central);
});

it('refuses outside a transaction, where SET LOCAL would do nothing, before running the closure', function () {
    $central = DB::scalar("select current_setting('search_path')");
    $ran = false;

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => SchemaSwitch::within(DB::connection(), 'switch_probe', function () use (&$ran): void {
            $ran = true;
        }))->toThrow(LogicException::class, 'The search path is switched with SET LOCAL, which needs an open transaction.')
        ->and($ran)->toBeFalse()
        ->and(DB::scalar("select current_setting('search_path')"))->toBe($central);
});

it('leaves a closure that throws to the rollback, which undoes the switch', function () {
    $central = DB::scalar("select current_setting('search_path')");

    expect(fn () => DB::transaction(fn () => SchemaSwitch::within(DB::connection(), 'switch_probe', function (): void {
        throw new RuntimeException('The work failed.');
    })))->toThrow(RuntimeException::class, 'The work failed.')
        ->and(DB::scalar("select current_setting('search_path')"))->toBe($central);
});
