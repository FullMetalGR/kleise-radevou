<?php

declare(strict_types=1);

namespace App\Http\Setup;

use App\Setup\SetupProgress;
use App\Tenancy\Tenant;

/**
 * One step of the setup wizard on the server: what its panel is handed inside Home's `setup`
 * prop, the admin word sections it reads beside setup's own, and its own writes. Each step is
 * one class, so a step grows its data, its words and its routes without touching another's.
 *
 * Every step's data is read only for an owner the wizard is offered to, inside the closure Home
 * renders `setup` from, never on an answer that leaves `setup` out.
 */
interface SetupStep
{
    /**
     * The sections of lang/{locale}/admin the step's panel reads besides `setup`, such as another
     * screen's whose words it borrows; Home asks for them only while the wizard is offered.
     *
     * @return list<string>
     */
    public function sections(): array;

    /**
     * What the step's panel receives as `setup.data.{step}`, or null where it needs nothing.
     *
     * @return array<string, mixed>|null
     */
    public function data(Tenant $tenant, SetupProgress $progress): ?array;

    /**
     * Registers the step's own writes inside the admin route group, each answering with a
     * redirect back to Home so the wizard stays open and Home's props arrive re-read.
     */
    public static function routes(): void;
}
