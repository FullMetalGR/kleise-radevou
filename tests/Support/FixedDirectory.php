<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\BusinessDirectory;
use App\Notifications\StaffOnBooking;
use App\Notifications\TenantUser;

/**
 * A directory answering the rows it was handed, with no database anywhere near it.
 */
final readonly class FixedDirectory implements BusinessDirectory
{
    /**
     * @param  list<TenantUser>  $users
     * @param  list<StaffOnBooking>  $staff
     */
    public function __construct(private array $users = [], private array $staff = []) {}

    public function users(): array
    {
        return $this->users;
    }

    public function staffOn(string $uuid): array
    {
        return $this->staff;
    }
}
