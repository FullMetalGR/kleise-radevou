<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use RuntimeException;
use Throwable;

/**
 * The delete guard's refusal: a service some booking item names, in any status, is not deleted.
 * The rows it is named by are history a customer paid for or was refused, and a row deleted
 * from under them would leave the items pointing at nothing. The way forward is to deactivate
 * the service, which the admin offers beside the button that earned this.
 *
 * The refusal is the foreign key's, migration 000012: the delete statement fails with SQLSTATE
 * 23503 and ServiceRecords reads that code and nothing else, the message being whatever
 * language the cluster's lc_messages selects. The sentence a user reads is the controller's,
 * ServiceController::REFERENCED, worded in the request's language.
 */
final class ServiceReferenced extends RuntimeException
{
    public const string CODE = 'referenced';

    /** foreign_key_violation: the key refused the delete because an item names the row. */
    public const string SQLSTATE = '23503';

    public function __construct(public readonly int $serviceId, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Service %d is named by booking items and cannot be deleted.', $serviceId), 0, $previous);
    }
}
