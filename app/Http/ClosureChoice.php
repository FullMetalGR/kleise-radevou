<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What the admin chose to do with the bookings inside a closure it adds: keep them where they
 * are, move them one by one afterwards, or cancel them all. Keep and move write the same thing,
 * the closure with the bookings left standing; the admin opens its list of bookings to move after
 * a move.
 */
enum ClosureChoice: string
{
    case Keep = 'keep';
    case Move = 'move';
    case Cancel = 'cancel';
}
