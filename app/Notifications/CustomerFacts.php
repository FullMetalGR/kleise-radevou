<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\Customer;
use App\Site\SiteLanguage;

/**
 * What a surface of the business may say about the customer: the name always, and the email and
 * the phone only where whoever is reading holds manageBookings.
 *
 * It is one class because three surfaces ask it - the new-booking notice, the approval request,
 * and the decision pages - and what a caller may see of a booking is one class's decision on
 * every surface. Spelled three times it would be three chances for one of them to keep showing a
 * phone number after somebody narrowed the rule.
 *
 * The answer arrives with the caller rather than being read here. A message is built for a
 * recipient, which carries what that mailbox may be shown, and a page for the user a signed
 * link names; the roles behind both are read where a role may be read, which is not here.
 *
 * The name is always there, because a name is how a person recognises their own morning, and
 * because the business is already party to the booking. A blank phone is left out entirely: the
 * field is optional on the booking form, and a label with nothing after it reads as a message
 * that lost something on the way.
 *
 * The facts are named by identifiers, customer, email and phone, and each label is a display key
 * of decision.php beside it, read in the language the surface is written in: so a label is words
 * and never a key of an array a reader iterates.
 */
final readonly class CustomerFacts
{
    public const string CUSTOMER = 'customer';

    public const string EMAIL = 'email';

    public const string PHONE = 'phone';

    /**
     * Each fact's label by its identifier, a display key of decision.php.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        self::CUSTOMER => 'decision.facts.customer',
        self::EMAIL => 'decision.facts.email',
        self::PHONE => 'decision.facts.phone',
    ];

    /**
     * What a page of the business says in place of the name where the booking's customer was
     * erased, with no way to reach them beside it, as the admin reads one: a display key of
     * decision.php.
     */
    public const string ERASED = 'decision.facts.erased';

    /**
     * The facts by identifier, in the language given, for a surface that lays them out itself:
     * the name, the email and the phone as the booking carries them, or, where the customer was
     * erased, which a decision page may yet be opened on and no message is built from, the
     * customer standing as the erased line in the language.
     *
     * @return array<string, string>
     */
    public static function of(?Customer $customer, bool $managesBookings, SiteLanguage $language): array
    {
        if ($customer === null) {
            return [self::CUSTOMER => DecisionLines::line(self::ERASED, $language)];
        }

        $facts = [self::CUSTOMER => $customer->name];

        if (! $managesBookings) {
            return $facts;
        }

        $facts[self::EMAIL] = $customer->email;

        if ($customer->phone !== null && trim($customer->phone) !== '') {
            $facts[self::PHONE] = $customer->phone;
        }

        return $facts;
    }

    /**
     * A fact's label in a language, by its identifier.
     */
    public static function label(string $fact, SiteLanguage $language): string
    {
        return DecisionLines::line(self::LABELS[$fact] ?? $fact, $language);
    }

    /**
     * The same facts as the lines of a message, one to a line, each label in the language given.
     */
    public static function lines(Customer $customer, bool $managesBookings, SiteLanguage $language): string
    {
        $lines = [];

        foreach (self::of($customer, $managesBookings, $language) as $fact => $value) {
            $lines[] = self::label($fact, $language).': '.$value;
        }

        return implode("\n", $lines);
    }
}
