<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The business page's text: what the owner writes in the Booking page section of Settings for
 * the hosted page to render above the flow, and Important info, the one text the review step
 * and the confirmation carry. Every field is null where the owner left it empty, and an empty
 * field renders nothing.
 */
final readonly class Profile
{
    /** The columns in the order the section lists them and the patch carries them. */
    public const array FIELDS = ['about', 'address_line1', 'address_line2', 'city', 'postal_code', 'country', 'phone', 'public_email', 'website', 'instagram', 'facebook', 'google_reviews_url', 'important_info'];

    /** The address's columns, the ones a save that changes any of moves the map. */
    public const array ADDRESS = ['address_line1', 'address_line2', 'city', 'postal_code', 'country'];

    public function __construct(
        public ?string $about = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $phone = null,
        public ?string $publicEmail = null,
        public ?string $website = null,
        public ?string $instagram = null,
        public ?string $facebook = null,
        public ?string $googleReviewsUrl = null,
        public ?string $importantInfo = null,
    ) {}

    /**
     * A row's columns by name, each a string or null.
     *
     * @param  array<string, string|null>  $columns
     */
    public static function of(array $columns): self
    {
        return new self(...array_map(fn (string $field): ?string => $columns[$field] ?? null, self::FIELDS));
    }

    /**
     * The address as the geocoder is asked it, the maps search spells it and the map's settling
     * write compares it: the street lines, the postal code before the city, and the country's
     * code, each line that is set. None where no street line, city or postal code is set, since a
     * country alone is every row's default and no address.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        return $this->lines($this->country);
    }

    /**
     * The address as the business page prints it: the lines above with the country's name in place
     * of its code, from the names given, countries.php in the page's language; a code outside them
     * prints as the code it is, since the save takes any two capitals.
     *
     * @param  array<string, string>  $names  the country names by code
     * @return list<string>
     */
    public function displayLines(array $names): array
    {
        return $this->lines($this->country === null ? null : ($names[$this->country] ?? $this->country));
    }

    /**
     * The address's lines ending in the country line given, each line that is set, and none where
     * no street line, city or postal code is set.
     *
     * @return list<string>
     */
    private function lines(?string $country): array
    {
        if ($this->addressLine1 === null && $this->city === null && $this->postalCode === null) {
            return [];
        }

        $locality = trim(($this->postalCode ?? '').' '.($this->city ?? ''));

        return array_values(array_filter(
            [$this->addressLine1, $this->addressLine2, $locality, $country],
            fn (?string $line): bool => $line !== null && $line !== '',
        ));
    }

    /**
     * The fields by column name, as the settings screen's values carry them.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return array_combine(self::FIELDS, [
            $this->about, $this->addressLine1, $this->addressLine2, $this->city, $this->postalCode, $this->country, $this->phone,
            $this->publicEmail, $this->website, $this->instagram, $this->facebook, $this->googleReviewsUrl, $this->importantInfo,
        ]);
    }
}
