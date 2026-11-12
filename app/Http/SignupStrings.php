<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Lifetime;
use App\Http\Requests\PasswordRule;
use App\Site\SiteLanguage;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\SlugProblem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use UnexpectedValueException;

/**
 * The signup's sentences: every one a key of lang/{en,el}/signup.php, read through the
 * translator in the request's language, the application's locale, which a Greek route's locale
 * middleware sets and the default leaves English. The server's refusals read one sentence each;
 * the pages read the sections they render, SECTIONS, as one prop with the language beside it,
 * through the central host's adapter, since the admin build refuses Greek and the pages hold no
 * sentence of their own. Nothing the pages do not read travels: the refusals, the flashes and the
 * categories' names, which the form receives as its own prop, stay on the server. The three
 * constants of the code a sentence may name, the slug's longest length and the password's floor
 * and ceiling, are filled here and spelled in no file, and the pending page's waiting sentence is
 * chosen here by the verification link's hours, the one line of the prop the server resolves.
 * The language itself is read here too, the one the reservation writes on the row, so the row
 * and the sentences the person read agree.
 */
final readonly class SignupStrings
{
    /** The file under lang/{locale}, and the prefix of every key. */
    public const string FILE = 'signup';

    /** The sections of the file the pages read, the form's and the pending page's, in this order. */
    public const array SECTIONS = ['form', 'pending'];

    /** The pending page's waiting sentence, a choice by the verification link's hours. */
    public const string WAITING = 'pending.waiting';

    public function __construct(private Translator $translator, private Application $app) {}

    /**
     * The language the signup is made in: the application's locale, which the signup route's
     * locale middleware set to the route's language, en or el. A locale outside the site's two
     * is refused here, since the row's CHECK would refuse it a moment later.
     */
    public function language(): SiteLanguage
    {
        return SiteLanguage::from($this->app->getLocale());
    }

    /**
     * One sentence by its key under the file, its placeholders filled; a key the file lacks reads
     * as the key, as the translator answers it.
     *
     * @param  array<string, string>  $replace
     */
    public function sentence(string $key, array $replace = []): string
    {
        $line = $this->translator->get(self::FILE.'.'.$key, $replace);

        return is_string($line) ? $line : self::FILE.'.'.$key;
    }

    /**
     * A refusal of the form by its key under errors, the code's constants filled in.
     */
    public function error(string $key): string
    {
        return $this->sentence('errors.'.$key, [
            'max' => (string) Slug::MAX_LENGTH,
            ...PasswordRule::fills(),
        ]);
    }

    /**
     * The slug's refusal for a problem, keyed by the problem's machine code. The console's free
     * tenant form keeps the enum's own English; this is the signup's.
     */
    public function slug(SlugProblem $problem): string
    {
        return $this->error('slug.'.$problem->value);
    }

    /**
     * The props the pages read: the request's language and the file's SECTIONS in it, the
     * placeholders unfilled, since the page fills them, but WAITING, chosen by the verification
     * link's hours with :count filled and the page's own placeholders left for it.
     *
     * @return array{language: string, strings: array{form: array<string, string>, pending: array<string, string>}}
     */
    public function props(): array
    {
        $pending = $this->section('pending');
        $pending['waiting'] = $this->translator->choice(self::FILE.'.'.self::WAITING, Lifetime::hours(SignupVerification::LINK_LIFETIME));

        return ['language' => $this->language()->value, 'strings' => ['form' => $this->section('form'), 'pending' => $pending]];
    }

    /**
     * One section of the file as the request's language holds it, its sentences unfilled; a
     * section the file lacks, or a line in it that is no sentence, is refused, since a page would
     * render the gap.
     *
     * @return array<string, string>
     */
    private function section(string $name): array
    {
        $lines = $this->translator->get(self::FILE.'.'.$name);

        if (! is_array($lines)) {
            throw new UnexpectedValueException(sprintf('%s.%s is no section of sentences.', self::FILE, $name));
        }

        $section = [];

        foreach ($lines as $key => $line) {
            $section[(string) $key] = is_string($line) ? $line : throw new UnexpectedValueException(sprintf('%s.%s.%s is no sentence.', self::FILE, $name, $key));
        }

        return $section;
    }
}
