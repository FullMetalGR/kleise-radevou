<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * The kind of business a signup names: the seven slugs the tenants table's CHECK allows, each with
 * the English label the console shows; the signup names them in signup.php's own words and no
 * admin page shows one. No behaviour hangs on it.
 */
enum BusinessCategory: string
{
    case HairSalon = 'hair_salon';
    case Barber = 'barber';
    case Nails = 'nails';
    case Beauty = 'beauty';
    case Massage = 'massage';
    case Physiotherapy = 'physiotherapy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HairSalon => 'Hair salon',
            self::Barber => 'Barber',
            self::Nails => 'Nails',
            self::Beauty => 'Beauty',
            self::Massage => 'Massage',
            self::Physiotherapy => 'Physiotherapy',
            self::Other => 'Other',
        };
    }
}
