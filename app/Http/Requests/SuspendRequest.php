<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The console's suspend: the operator's reason as OperatorReasonRequest takes it, a missing one
 * refused with the suspension's own sentence.
 *
 * Nothing here decides whether the tenant may be suspended: that is the row's status, read again
 * under its lock by App\Platform\Suspension, whose refusal is the controller's flash.
 */
final class SuspendRequest extends OperatorReasonRequest
{
    public const string REQUIRED = 'Give the reason for the suspension. The owners are sent it.';

    protected function required(): string
    {
        return self::REQUIRED;
    }
}
