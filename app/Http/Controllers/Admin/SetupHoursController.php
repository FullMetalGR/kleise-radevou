<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\ErrorEnvelope;
use App\Http\Requests\OpeningHoursRequest;
use App\Http\Setup\SetupWizard;
use App\Infrastructure\Db\ShiftRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Opening hours step's save, PUT /admin/setup/hours: the week judged by Settings' own rules
 * (OpeningHoursRequest), then written where the step shows it. Without the staff feature that is
 * the implicit member's week, the write Settings' Opening hours makes; with it, every member the
 * Shifts screen lists is given the week in one transaction (ShiftRecords::replaceEveryWeek).
 * Either stamps the hours marker, which closes the step.
 *
 * A week with no window is refused here, in either mode, as the 400 naming the week with the
 * step's own sentence, and nothing is written: Settings may save a closed week, but the step is
 * there to give the business hours, and with the staff feature an empty week would drop every
 * listed member's pattern at once.
 *
 * Refused with the 403 to anyone not in setup, an account recorded as finished included, so the
 * step's write never runs outside setup. It records nothing about setup itself: where every step
 * is closed by the time it runs it lands all the same, and the Home answer after it says so.
 * Answers with a redirect back, Home where the request names no page, Home's props arriving
 * re-read.
 */
final readonly class SetupHoursController
{
    /** The sentence an empty week is refused with, the one the step's panel shows under an emptied editor. */
    private const string EMPTY = 'admin/setup.steps.hours.empty';

    public function __construct(
        private CurrentTenant $current,
        private AdminSession $session,
        private SetupWizard $wizard,
        private ShiftRecords $shifts,
        private StaffRecords $staff,
    ) {}

    public function __invoke(OpeningHoursRequest $request): RedirectResponse|JsonResponse
    {
        $tenant = $this->current->get();
        $viewer = $this->session->principal($request);
        $progress = $viewer === null ? null : $this->wizard->progress($tenant, $viewer);

        if ($progress === null) {
            throw new AccessDeniedHttpException;
        }

        if ($request->windows() === []) {
            return (new ErrorEnvelope('invalid_request', 400, AdminLines::line(self::EMPTY), ['field' => OpeningHoursRequest::FIELD]))->toResponse();
        }

        if ($progress->applies('team')) {
            $this->shifts->replaceEveryWeek($tenant, $request->windows());
        } else {
            $this->staff->replaceWeek($tenant, $request->windows());
        }

        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }
}
