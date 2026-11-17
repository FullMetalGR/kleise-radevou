<?php

declare(strict_types=1);

namespace App\Http\Setup;

use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Billing\SubscriptionState;
use App\Http\Principal;
use App\Http\Setup\Steps\HoursStep;
use App\Http\Setup\Steps\LinkStep;
use App\Http\Setup\Steps\PaymentsStep;
use App\Http\Setup\Steps\ProfileStep;
use App\Http\Setup\Steps\ServicesStep;
use App\Http\Setup\Steps\TeamStep;
use App\Infrastructure\Db\SetupRecords;
use App\Platform\ClosingWindow;
use App\Setup\SetupProgress;
use App\Site\Demo;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The setup wizard as Home offers it. Only an owner in their own session is offered it, read as
 * holding manageBilling, the one permission no other role and no support session holds; never on
 * the demo account, never while the subscription has lapsed or the account is closing, when the
 * full screens refuse their writes, and never once setup is recorded as finished.
 *
 * Reading the progress records nothing, whether or not every step that applies is done or skipped
 * by now: a step can close by another hand, a provider's notice or a second tab, and a write of
 * the wizard's that runs after it still lands. The record is Home's to write (finish), by the one
 * answer that tells the page setup is complete, so the page says so once and only as it happens,
 * and an account whose data already covers every step never sees the wizard or its banner.
 *
 * What an owner in setup is handed is Home's `setup` prop: the steps that apply with their facts,
 * whether the wizard ever came up by itself, the step it opens on by itself on this answer, and
 * each step's own data. The automatic opening is the first open step while the business has no
 * record of one; the page records it once the wizard is really on screen, so an answer fetched
 * and never shown does not spend it. A write that leaves Home and comes back for one step names
 * that step in the OPEN_ON flash, and the wizard opens on it.
 */
final readonly class SetupWizard
{
    /** The admin section the wizard's own words live in. */
    private const string SECTION = 'setup';

    /** The session flash naming a step the wizard opens on at the next Home answer. */
    public const string OPEN_ON = 'setup_open_on';

    /** Each step's class by its key, in the order the wizard walks them. */
    private const array STEPS = [
        'profile' => ProfileStep::class,
        'services' => ServicesStep::class,
        'team' => TeamStep::class,
        'hours' => HoursStep::class,
        'payments' => PaymentsStep::class,
        'link' => LinkStep::class,
    ];

    public function __construct(
        private SetupRecords $records,
        private Demo $demo,
        private EntitlementResolver $entitlements,
        private Container $container,
    ) {}

    /**
     * The business's progress while this viewer is in setup: one the wizard may be offered to,
     * on an account not yet recorded as finished, every step closed by now or not. Null for
     * anyone else. It records nothing.
     */
    public function progress(Tenant $tenant, Principal $viewer): ?SetupProgress
    {
        if (! $viewer->holds(Permission::ManageBilling) || $this->demo->is($tenant) || self::lapsed($tenant)) {
            return null;
        }

        $progress = $this->records->progress($tenant, $this->entitlements->resolve($tenant)->allows(Feature::Staff));

        return $progress->finished ? null : $progress;
    }

    /**
     * Records setup as finished. Home's answer alone calls it, as it tells the page that every
     * step is closed; the reading after it finds the record and offers nothing.
     */
    public function finish(Tenant $tenant): void
    {
        $this->records->markFinished($tenant);
    }

    /**
     * The admin sections the wizard reads: its own, then those each step that applies names.
     *
     * @return list<string>
     */
    public function sections(SetupProgress $progress): array
    {
        $sections = [self::SECTION];

        foreach ($progress->keys() as $key) {
            array_push($sections, ...$this->step($key)->sections());
        }

        return array_values(array_unique($sections));
    }

    /**
     * Home's `setup` prop.
     *
     * @return array{steps: list<array{key: string, done: bool, skipped: bool}>, opened: bool, opens: string|null, data: array<string, array<string, mixed>|null>}
     */
    public function payload(Tenant $tenant, SetupProgress $progress, Request $request): array
    {
        $data = [];

        foreach ($progress->keys() as $key) {
            $data[$key] = $this->step($key)->data($tenant, $progress);
        }

        return [
            'steps' => $progress->steps(),
            'opened' => $progress->opened,
            'opens' => self::opensOn($progress, $request->session()->get(self::OPEN_ON)),
            'data' => $data,
        ];
    }

    /**
     * Registers each step's own writes, in the steps' order, inside the admin route group.
     */
    public static function stepRoutes(): void
    {
        foreach (self::STEPS as $step) {
            $step::routes();
        }
    }

    /** The step a flash names when it applies, else the first open one until the wizard has come up by itself. */
    private static function opensOn(SetupProgress $progress, mixed $named): ?string
    {
        if (is_string($named) && $progress->applies($named)) {
            return $named;
        }

        return $progress->opened ? null : $progress->firstOpen();
    }

    private static function lapsed(Tenant $tenant): bool
    {
        return ! SubscriptionState::from($tenant->subscription_state)->isActive() || ClosingWindow::of($tenant) !== null;
    }

    private function step(string $key): SetupStep
    {
        return $this->container->make(self::STEPS[$key]);
    }
}
