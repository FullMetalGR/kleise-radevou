<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A paragraph of a branded mail that a person wrote in lines of their own, a message of several
 * lines: the one kind of text paragraph whose line breaks the HTML part keeps, each drawn as a
 * break inside the paragraph, the text escaped first as every paragraph's is. The text part prints
 * it as written, as it prints any paragraph.
 *
 * A paragraph handed as a bare string asks for nothing, so a line break in it is no break on the
 * page: a name or a business's name someone typed with line breaks in it cannot set lines of its
 * own into a mail's sentence.
 */
final readonly class MailLines
{
    public function __construct(public string $text) {}
}
