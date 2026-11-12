<?php

use App\Infrastructure\Db\RowShape;

/*
 * The guard the admin's readers put over a row a statement handed back: each column read as the
 * type the schema declares or refused, and the one sentence of the refusal naming the rows and
 * the tenant, never the value, which may be a customer's. BookingRecords, CustomerRecords,
 * ReportFigures and CalendarRoster read through it.
 */

it('reads a row and each column as the type the schema declares', function () {
    $shape = new RowShape('reports', 'luna');
    $row = (object) ['bookings' => 3, 'status' => 'confirmed', 'earning' => true];

    expect($shape->row($row))->toBe($row)
        ->and($shape->int($row->bookings))->toBe(3)
        ->and($shape->string($row->status))->toBe('confirmed')
        ->and($shape->bool($row->earning))->toBeTrue();
});

it('refuses a value of another type in the one sentence, naming the rows and the tenant and never the value', function (Closure $read) {
    expect(fn () => $read(new RowShape('customers', 'luna')))
        ->toThrow(LogicException::class, 'A customers row of tenant "luna" is not shaped as the schema declares.');
})->with([
    'a row that is no object' => [fn (RowShape $shape) => $shape->row(['casey@example.com'])],
    'an int that is text' => [fn (RowShape $shape) => $shape->int('casey@example.com')],
    'a string that is null' => [fn (RowShape $shape) => $shape->string(null)],
    'a bool that is a number' => [fn (RowShape $shape) => $shape->bool(1)],
]);

it('answers the sentence it throws for a reader that finds a row misshapen on its own reading', function () {
    expect((new RowShape('bookings', 'pier'))->misshapen()->getMessage())
        ->toBe('A bookings row of tenant "pier" is not shaped as the schema declares.');
});
