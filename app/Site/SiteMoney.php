<?php

declare(strict_types=1);

namespace App\Site;

use App\Domain\Money\Money;
use NumberFormatter;

/**
 * An amount as a page of the site spells it: in the page's language, in the amount's own currency
 * and that currency's scale, so the Greek page writes a decimal comma and the English one a
 * decimal point, each placing the currency's symbol as its language does. The digits after the
 * separator are the currency's, from Currency, never a divisor of a hundred. The symbol is the
 * formatter's, so no file holds a euro sign. Where the formatter cannot spell the amount the plain
 * decimal and the code stand in.
 */
final class SiteMoney
{
    public static function format(Money $money, SiteLanguage $language): string
    {
        $digits = $money->currency->digits();
        $formatter = new NumberFormatter($language->value, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $spelled = $formatter->formatCurrency((float) $money->decimal(), $money->currency->code);

        return is_string($spelled) ? $spelled : $money->decimal().' '.$money->currency->code;
    }
}
