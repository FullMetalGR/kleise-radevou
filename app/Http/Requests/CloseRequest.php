<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The console's close: the operator's reason as OperatorReasonRequest takes it, as the suspension
 * takes its own, a missing one refused with the close's own sentence.
 *
 * Nothing here decides whether the tenant may be closed: that is the row's status, read again
 * under its lock by App\Platform\AccountClose and the operator's closer, whose refusal is the
 * controller's flash.
 */
final class CloseRequest extends OperatorReasonRequest
{
    public const string REQUIRED = 'Give the reason for the close. The owners are sent it.';

    protected function required(): string
    {
        return self::REQUIRED;
    }
}
