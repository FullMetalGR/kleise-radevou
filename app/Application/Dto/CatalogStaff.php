<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * One staff member as the public catalog names them: the id the booking app sends back as a
 * pick, the name it shows, and the face and line the staff step shows beside it: the URL of
 * the member's photo on the tenant's own origin, their title, and the bio the step discloses
 * behind View profile, each null where the member has none. An email, a linked user, a status
 * and a sort reach no customer.
 */
final readonly class CatalogStaff
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $photoUrl = null,
        public ?string $title = null,
        public ?string $bio = null,
    ) {}
}
