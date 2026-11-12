<?php

declare(strict_types=1);

namespace App\Http;

use App\Billing\Feature;

/**
 * The features as the console offers them: one checkbox per case of Feature, on the free
 * tenant's form and on the detail's Change features, each with its name and one line saying
 * what it opens. The match is over every case, so a feature added to the enum is not offered
 * until it is worded here, and static analysis says so.
 */
final readonly class ConsoleFeatures
{
    public const string STAFF_LABEL = 'Staff';

    public const string STAFF_HELP = 'More than one staff member, each with their own hours, services, calendar and approvals.';

    /**
     * @return list<array{value: string, label: string, help: string}>
     */
    public static function choices(): array
    {
        return array_map(fn (Feature $feature): array => match ($feature) {
            Feature::Staff => ['value' => $feature->value, 'label' => self::STAFF_LABEL, 'help' => self::STAFF_HELP],
        }, Feature::cases());
    }
}
