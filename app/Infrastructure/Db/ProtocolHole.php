<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

/**
 * A constraint refused a row the write protocol should have made impossible. The constraint is
 * the backstop; reaching it means the locks in front of it have a hole. Answered as a conflict,
 * never retried, and reported at the loudest level the runner uses.
 */
abstract class ProtocolHole extends TransactionFailure {}
