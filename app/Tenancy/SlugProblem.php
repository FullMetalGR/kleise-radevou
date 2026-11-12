<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Why a slug was refused. The backed value is the machine code an error on the wire carries; the
 * detail is the sentence shown verbatim.
 */
enum SlugProblem: string
{
    case Empty = 'empty';
    case InvalidCharacter = 'invalid_character';
    case LeadingHyphen = 'leading_hyphen';
    case TrailingHyphen = 'trailing_hyphen';
    case PunycodeForm = 'punycode_form';
    case TooLong = 'too_long';
    case Reserved = 'reserved';
    case Taken = 'taken';
    case HeldBySignup = 'held_by_signup';

    public function detail(): string
    {
        return match ($this) {
            self::Empty => 'Choose a subdomain name.',
            self::InvalidCharacter => 'Use only letters, digits and hyphens.',
            self::LeadingHyphen => 'A subdomain name cannot start with a hyphen.',
            self::TrailingHyphen => 'A subdomain name cannot end with a hyphen.',
            self::PunycodeForm => 'A subdomain name cannot have hyphens in its third and fourth positions.',
            self::TooLong => 'A subdomain name is at most '.Slug::MAX_LENGTH.' characters.',
            self::Reserved => 'That subdomain name is reserved.',
            self::Taken => 'That subdomain name is already taken.',
            self::HeldBySignup => 'That subdomain name is held by a signup in progress. It frees within a day if that signup is not verified.',
        };
    }
}
