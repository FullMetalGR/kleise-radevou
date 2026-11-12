<?php

use App\Application\Clock;
use App\Notifications\BrandedMail;
use App\Notifications\BrandedMails;
use App\Notifications\MailLink;
use App\Site\SiteLanguage;
use Illuminate\Support\Facades\File;
use Tests\Support\FixedClock;

/*
 * The platform's branded mail: a tenant-facing mail's words read from mail.php in the tenant's
 * language, the text part joined from its paragraphs, and the HTML part rendered in the one
 * layout, one column, the wordmark as styled text, the brand's colours inline as the tokens'
 * values, no remote image and no pixel. The layout is a view and the strings are files, so
 * nothing here touches the database and the file is unwrapped. The case that tells the two
 * languages apart lays a line over the loaded Greek file, as ContactPageTest does, to tell the
 * test's line from the file's whatever the file holds. The instant case spells a date, so the
 * clock is pinned first, though nothing here reads it.
 */

beforeEach(fn () => app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00')));

/**
 * The value a custom property of the brand's tokens file holds, as the file spells it.
 */
function brandedToken(string $property): string
{
    $tokens = File::get(resource_path('js/brand/brand.css'));

    if (preg_match('/^\s*--brand-'.preg_quote($property, '/').':\s*([^;]+);/m', $tokens, $match) !== 1) {
        throw new LogicException(sprintf('The brand\'s tokens file declares no --brand-%s.', $property));
    }

    return trim($match[1]);
}

/**
 * A mail's paragraphs with a character to escape in each kind of place: the reader's name, the
 * business's, and a link with a query to keep whole.
 *
 * @return list<string|MailLink>
 */
function brandedParagraphs(): array
{
    return [
        'Hello Ada <Person>,',
        'Confirm your email to set up Luna & Co at https://luna.kleiseradevou.test. Open this link:',
        new MailLink('https://kleiseradevou.test/signup/verify/abc?x=1&y=2'),
        'If you did not sign up, ignore this email and nothing is set up.',
    ];
}

function brandedMail(SiteLanguage $language = SiteLanguage::English): BrandedMail
{
    return app(BrandedMails::class)->in($language);
}

it('joins the paragraphs by a blank line for the text part, a link as its bare address, the words as given', function () {
    expect(BrandedMail::text(brandedParagraphs()))->toBe(implode("\n", [
        'Hello Ada <Person>,',
        '',
        'Confirm your email to set up Luna & Co at https://luna.kleiseradevou.test. Open this link:',
        '',
        'https://kleiseradevou.test/signup/verify/abc?x=1&y=2',
        '',
        'If you did not sign up, ignore this email and nothing is set up.',
    ]));
});

it('renders the HTML part in the layout: one document in the language, the subject its title, the wordmark the public name as styled text, each paragraph escaped, the link an anchor to itself, the footer the reason given and the platform named from mail.php', function () {
    $html = brandedMail()->html('Confirm your email', brandedParagraphs(), 'You received this email because this address signed up.');

    expect($html)->toStartWith('<!doctype html>')
        ->and($html)->toContain('<html lang="en">')
        ->and($html)->toContain('<meta charset="utf-8">')
        ->and($html)->toContain('<title>Confirm your email</title>')
        ->and($html)->toContain('>'.siteName().'</span>')
        ->and($html)->toContain('Hello Ada &lt;Person&gt;,')
        ->and($html)->toContain('set up Luna &amp; Co at https://luna.kleiseradevou.test. Open this link:')
        ->and($html)->toContain('<a href="https://kleiseradevou.test/signup/verify/abc?x=1&amp;y=2"')
        ->and($html)->toContain('>https://kleiseradevou.test/signup/verify/abc?x=1&amp;y=2</a>')
        ->and($html)->toContain('If you did not sign up, ignore this email and nothing is set up.')
        ->and($html)->toContain('You received this email because this address signed up.')
        ->and($html)->toContain(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and($html)->not->toContain('<Person>')
        ->and($html)->not->toContain('mail.layout')
        ->and(preg_match('/[^\x00-\x7F]/', $html))->toBe(0)
        ->and(substr_count($html, '<p '))->toBe(6);
});

it('inlines the brand\'s colours and type as the tokens file spells them, the primary on the wordmark and the rule, through no stylesheet, no script, no remote image and no pixel: the only addresses are the mail\'s own', function () {
    $html = brandedMail()->html('Confirm your email', brandedParagraphs(), 'Because you signed up.');

    preg_match_all('~https?://[^\s"<]+~', $html, $addresses);

    expect($html)->toContain('color:'.brandedToken('primary').';')
        ->and($html)->toContain('border-top:4px solid '.brandedToken('primary').';')
        ->and($html)->toContain('background:'.brandedToken('paper').';')
        ->and($html)->toContain('background:'.brandedToken('surface').';')
        ->and($html)->toContain('color:'.brandedToken('ink').';')
        ->and($html)->toContain('color:'.brandedToken('muted').';')
        ->and($html)->toContain('border-top:1px solid '.brandedToken('line').';')
        ->and($html)->toContain('font-family:'.e(brandedToken('font')).';')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<link')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<style')
        ->and($html)->not->toContain('url(')
        ->and($html)->not->toContain('var(--')
        ->and(array_values(array_unique($addresses[0])))->toBe(['https://luna.kleiseradevou.test.', 'https://kleiseradevou.test/signup/verify/abc?x=1&amp;y=2']);
});

it('reads the layout\'s strings in the language asked for, lang/el\'s for a Greek mail, with the document in that language', function () {
    __('mail.greeting', [], 'el');
    app('translator')->addLines(['mail.layout.sent_by' => 'GREEK SENT BY :name'], 'el');

    $greek = brandedMail(SiteLanguage::Greek);
    $html = $greek->html('Confirm your email', brandedParagraphs(), 'Because you signed up.');

    expect($html)->toContain('<html lang="el">')
        ->and($html)->toContain('GREEK SENT BY '.siteName())
        ->and($greek->language)->toBe(SiteLanguage::Greek)
        ->and(brandedMail()->html('Confirm your email', brandedParagraphs(), 'Because you signed up.'))->not->toContain('GREEK SENT BY');
});

it('reads a mail\'s own lines from mail.php in its language, the public name filled on every line and the placeholders given beside it, and answers the key for a line the file lacks', function () {
    __('mail.greeting', [], 'el');
    app('translator')->addLines(['mail.greeting' => 'GREEK HELLO :owner, FROM :name'], 'el');
    $this->allowMissingKey('mail.no.such.line');

    expect(brandedMail()->line('greeting', ['owner' => 'Ada Person']))->toBe('Hello Ada Person,')
        ->and(brandedMail(SiteLanguage::Greek)->line('greeting', ['owner' => 'Ada Person']))->toBe('GREEK HELLO Ada Person, FROM '.siteName())
        ->and(brandedMail()->line('layout.sent_by'))->toBe(siteSentence('mail.layout.sent_by', SiteLanguage::English))
        ->and(brandedMail()->line('layout.sent_by'))->toContain(siteName())
        ->and(brandedMail()->line('no.such.line'))->toBe('mail.no.such.line');
});

it('spells an instant in the tenant\'s clock through the locale-aware formatter, joined by the language\'s own sentence: the English as every message spells it today', function () {
    $instant = new DateTimeImmutable('2026-10-08 09:00:00', new DateTimeZone('UTC'));

    __('mail.greeting', [], 'el');
    app('translator')->addLines(['mail.time.instant' => 'GREEK :time ON :date'], 'el');

    $greek = brandedMail(SiteLanguage::Greek)->instant($instant, new DateTimeZone('Europe/Athens'));
    $formatter = new IntlDateFormatter('el', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'Europe/Athens', IntlDateFormatter::GREGORIAN, 'EEEE d MMMM y');

    expect(brandedMail()->instant($instant, new DateTimeZone('Europe/Athens')))->toBe('Thursday 8 October 2026 at 12:00')
        ->and(brandedMail()->instant($instant, new DateTimeZone('America/New_York')))->toBe('Thursday 8 October 2026 at 05:00')
        ->and($greek)->toBe('GREEK 12:00 ON '.$formatter->format($instant))
        ->and($greek)->not->toContain('Thursday');
});
