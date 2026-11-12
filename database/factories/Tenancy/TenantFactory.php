<?php

namespace Database\Factories\Tenancy;

use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->lexify('tenant-??????'),
            'name' => fake()->company(),
            'timezone' => 'Europe/Athens',
            'currency' => 'EUR',
        ];
    }
}
