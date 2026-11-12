<?php

declare(strict_types=1);

namespace App\Application\Notification;

use InvalidArgumentException;

/**
 * A file a message carries: the name it arrives under, what it is, and its bytes.
 *
 * The contents are held rather than a path, because the only attachment this product sends is
 * a calendar file built for one booking and written nowhere. Nothing above the port knows how
 * it is attached.
 */
final readonly class Attachment
{
    public function __construct(
        public string $name,
        public string $contentType,
        public string $contents,
    ) {
        if (trim($name) === '' || trim($contentType) === '') {
            throw new InvalidArgumentException('An attachment needs a name and a content type.');
        }
    }
}
