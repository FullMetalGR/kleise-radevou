<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Notifications\BusinessDirectory;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The one place the business directory is built for a tenant, so the rule that decides who
 * hears about a booking and the rows it reads can never belong to different tenants.
 *
 * The same shape as Messengers and TransitionPublishers, and for the same reason: a job knows
 * the tenant it was queued for and nothing else about it, and what it asks the container for
 * must therefore be a factory rather than a bound instance.
 */
final readonly class BusinessDirectories
{
    public function __construct(private DatabaseManager $db) {}

    public function for(Tenant $tenant): BusinessDirectory
    {
        return new PostgresBusinessDirectory($this->db, $tenant);
    }
}
