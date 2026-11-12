<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

/**
 * Which services and members a chain may draw on, chosen by the caller where the public road and
 * the admin's road part, before the catalog is read.
 *
 * Online is the public road, the booking app's availability read and its hold: the active
 * services offered online, each with its active assigned members offered online, the implicit
 * member among them as the engine always counts it, so "No preference" never lands on a member
 * the owner keeps off the public page and a pick of one is refused as not performing. Whole is
 * every other caller: the admin's wizard, its availability read and its create, and a move of a
 * booking already sold, which pins the member it was sold to whatever the flag says now.
 *
 * The flag narrows who the engine is asked about; how the engine answers, the masks and their
 * algebra, is the same on both.
 */
enum Pool
{
    case Online;
    case Whole;
}
