<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Tenancy\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two demo tenants for development, with different names and different themes, so two browser
 * tabs side by side show two brands from one application. Each is provisioned through the
 * tenant:provision command, the one way a tenant is made, luna with the staff feature its three
 * members need and pier on the floor, both with no subscription since the command bypasses
 * billing, and then given its theme in its own settings row, luna violet and pier blue; the
 * catalog seeder then fills both with staff and services and the hours seeder gives luna's staff
 * their weeks and blackouts. Running it twice changes nothing: the command finds the active row,
 * the theme is written only where it differs, and the catalog and the hours are inserted only
 * where absent.
 *
 * Runs under db:seed, from the console or from a test's seed(), which is what hands it the
 * command it prints through.
 */
final class DemoTenantsSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name: string, timezone: string, currency: string, theme: string, features: string}>
     */
    public const array TENANTS = [
        ['slug' => 'luna', 'name' => 'Luna Hair Studio', 'timezone' => 'Europe/Athens', 'currency' => 'EUR', 'theme' => 'violet', 'features' => 'staff'],
        ['slug' => 'pier', 'name' => 'Pier Physiotherapy', 'timezone' => 'Europe/London', 'currency' => 'GBP', 'theme' => 'blue', 'features' => ''],
    ];

    public function run(): void
    {
        foreach (self::TENANTS as $demo) {
            $this->provision($demo);
            $this->brand($demo['slug'], $demo['theme']);
        }

        $this->call(DemoCatalogSeeder::class);
        $this->call(DemoHoursSeeder::class);
    }

    /**
     * @param  array{slug: string, name: string, timezone: string, currency: string, theme: string, features: string}  $demo
     */
    private function provision(array $demo): void
    {
        $code = Artisan::call('tenant:provision', [
            'slug' => $demo['slug'],
            '--name' => $demo['name'],
            '--timezone' => $demo['timezone'],
            '--currency' => $demo['currency'],
            '--owner-name' => 'Demo Owner',
            '--owner-email' => sprintf('owner@%s.example', $demo['slug']),
            '--owner-password' => 'password',
            '--features' => $demo['features'],
        ], $this->command->getOutput());

        if ($code !== 0) {
            throw new RuntimeException(sprintf('Provisioning the demo tenant "%s" failed with exit code %d.', $demo['slug'], $code));
        }
    }

    /**
     * Writes the theme into the tenant's settings row where it differs, by a schema-qualified
     * name and with no search path change, the same way the page reads it.
     */
    private function brand(string $slug, string $theme): void
    {
        $changed = DB::table(Slug::of($slug)->schemaName().'.settings')
            ->where('id', 1)
            ->where('theme', '<>', $theme)
            ->update(['theme' => $theme, 'updated_at' => DB::raw('now()')]);

        $this->say($changed === 0
            ? sprintf('Theme of "%s" is already %s; nothing changed.', $slug, $theme)
            : sprintf('Theme of "%s" set to %s.', $slug, $theme));
    }

    private function say(string $line): void
    {
        $this->command->line($line);
    }
}
