<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminSession;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The retired My calendar address: a bookmark of it lands on the Calendar, which a staff member
 * now opens pinned to their own row. The target is the Calendar's route by name, so a move of
 * that route carries the redirect with it. Any principal is sent there, support included, and
 * the Calendar's own permission then answers.
 */
final readonly class RetiredMyCalendarController
{
    public function __construct(private CurrentTenant $current) {}

    public function __invoke(Request $request): RedirectResponse
    {
        return redirect(AdminSession::at($request, route('admin.calendar', ['tenant' => $this->current->get()->slug], absolute: false)));
    }
}
