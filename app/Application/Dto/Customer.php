<?php

declare(strict_types=1);

namespace App\Application\Dto;

use InvalidArgumentException;

/**
 * Who the booking is for. Guests only: a name, an address to reach them at, and a phone when
 * they gave one. Shape is checked here; whether the address is deliverable is the HTTP
 * boundary's question, asked before this is built.
 */
final readonly class Customer
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A customer has a name.');
        }

        if (trim($email) === '') {
            throw new InvalidArgumentException('A customer has an email address.');
        }
    }
}
