<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A paragraph of a branded mail that is a link: the one kind of paragraph the two parts spell
 * differently. The text part prints the address bare, as every message of the platform has, and
 * the HTML part prints it as an anchor to itself, so a reader of either part sees the same
 * address and no label stands between them and it.
 */
final readonly class MailLink
{
    public function __construct(public string $href) {}
}
