<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Site\Brand;
use App\Site\SiteLanguage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * A platform mail in one language: its lines read from lang/{language}/mail.php, an instant in
 * the tenant's clock spelled in that language, and its two parts from one list of paragraphs,
 * so the text and the HTML can never say different things.
 *
 * The text part is the paragraphs joined by a blank line, character for character what every
 * platform mail said before it was branded. The HTML part is the same paragraphs in the one
 * layout, resources/views/mail/platform.blade.php: one column, the wordmark as styled text, the
 * brand's colours and type inline as the tokens file spells them, no remote image and no
 * tracking pixel, and a footer naming why the mail was sent and the platform that sent it. The
 * layout reads no translator and no request: every string reaches it from here, already in the
 * language, and it escapes each.
 *
 * Every line may name the public name as :name, filled on every read, as the site's files do;
 * the reader's own name is :owner, since the reader of every mail here is the business's owner.
 */
final readonly class BrandedMail
{
    /** The file under lang/{language}, and the prefix of every key. */
    public const string FILE = 'mail';

    /** The one layout. */
    public const string VIEW = 'mail.platform';

    public function __construct(
        private Translator $translator,
        private ViewFactory $views,
        private Brand $brand,
        public SiteLanguage $language,
    ) {}

    /**
     * One line by its key under the file, in this language, its placeholders filled and the
     * public name among them; a key the file lacks reads as the key, as the translator answers it.
     *
     * @param  array<string, string>  $replace
     */
    public function line(string $key, array $replace = []): string
    {
        $line = $this->translator->get(self::FILE.'.'.$key, ['name' => $this->brand->name, ...$replace], $this->language->value);

        return is_string($line) ? $line : self::FILE.'.'.$key;
    }

    /**
     * One line read as a choice by a count, :count the count as digits, the public name and the
     * other placeholders filled as line() fills them.
     *
     * @param  array<string, string>  $replace
     */
    public function choice(string $key, int $count, array $replace = []): string
    {
        return $this->translator->choice(self::FILE.'.'.$key, $count, ['name' => $this->brand->name, ...$replace], $this->language->value);
    }

    /**
     * An instant in the tenant's clock, spelled in this language: the formatter's date and time
     * joined by the language's own sentence, "Thursday 8 October 2026 at 12:00" in English.
     */
    public function instant(DateTimeImmutable $instant, DateTimeZone $zone): string
    {
        return $this->line('time.instant', TenantTime::spelledIn($instant, $zone, $this->language));
    }

    /**
     * The text part: the paragraphs joined by a blank line, a link as its bare address.
     *
     * @param  list<string|MailLink>  $paragraphs
     */
    public static function text(array $paragraphs): string
    {
        return implode("\n\n", array_map(
            fn (string|MailLink $paragraph): string => $paragraph instanceof MailLink ? $paragraph->href : $paragraph,
            $paragraphs,
        ));
    }

    /**
     * The HTML part: the same paragraphs in the layout, the subject as the document's title, and
     * the footer giving the reason the mail was sent beside the platform's name.
     *
     * @param  list<string|MailLink>  $paragraphs
     */
    public function html(string $subject, array $paragraphs, string $reason): string
    {
        return $this->views->make(self::VIEW, [
            'language' => $this->language->value,
            'subject' => $subject,
            'name' => $this->brand->name,
            'tokens' => $this->brand->tokens(),
            'paragraphs' => array_map(
                fn (string|MailLink $paragraph): array => $paragraph instanceof MailLink
                    ? ['text' => $paragraph->href, 'href' => $paragraph->href]
                    : ['text' => $paragraph, 'href' => null],
                $paragraphs,
            ),
            'reason' => $reason,
            'sentBy' => $this->line('layout.sent_by'),
        ])->render();
    }
}
