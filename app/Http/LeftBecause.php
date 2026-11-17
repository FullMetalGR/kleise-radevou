<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Why one write of a run of them left its booking as it was, in the order a sentence that counts
 * them names them: the booking changed meanwhile, its guard refusing it or the booking gone; it
 * was busy, its locks taken by another write; or its write failed some other way, which the
 * exception handler was handed.
 */
enum LeftBecause: string
{
    case Changed = 'changed';
    case Busy = 'busy';
    case Failed = 'failed';
}
