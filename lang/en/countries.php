<?php

/*
 * The 39 countries the Settings screen's select offers, by code, each with its English name. This
 * file is the server's one list of countries, and tests/Unit/CountryTest.php holds its codes to
 * the Settings select's. The business page's address line and the Settings select read it, and no
 * country name is read from ICU; a code outside the list prints as the code it is. The keys are
 * the codes as the settings row stores them, two capitals.
 */

return [
    'AL' => 'Albania',
    'AT' => 'Austria',
    'AU' => 'Australia',
    'BE' => 'Belgium',
    'BG' => 'Bulgaria',
    'CA' => 'Canada',
    'CH' => 'Switzerland',
    'CY' => 'Cyprus',
    'CZ' => 'Czechia',
    'DE' => 'Germany',
    'DK' => 'Denmark',
    'EE' => 'Estonia',
    'ES' => 'Spain',
    'FI' => 'Finland',
    'FR' => 'France',
    'GB' => 'United Kingdom',
    'GR' => 'Greece',
    'HR' => 'Croatia',
    'HU' => 'Hungary',
    'IE' => 'Ireland',
    'IS' => 'Iceland',
    'IT' => 'Italy',
    'LT' => 'Lithuania',
    'LU' => 'Luxembourg',
    'LV' => 'Latvia',
    'ME' => 'Montenegro',
    'MK' => 'North Macedonia',
    'MT' => 'Malta',
    'NL' => 'Netherlands',
    'NO' => 'Norway',
    'PL' => 'Poland',
    'PT' => 'Portugal',
    'RO' => 'Romania',
    'RS' => 'Serbia',
    'SE' => 'Sweden',
    'SI' => 'Slovenia',
    'SK' => 'Slovakia',
    'TR' => 'Turkey',
    'US' => 'United States',
];
