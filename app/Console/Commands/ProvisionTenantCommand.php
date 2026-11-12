<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Entitlements;
use App\Billing\Feature;
use App\Site\SiteLanguage;
use App\Tenancy\InvalidSlug;
use App\Tenancy\ProvisioningRefused;
use App\Tenancy\Signup;
use App\Tenancy\Slug;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantUrls;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A stand-in for the signup webhook, kept as the development and demo path: it bypasses billing,
 * writing an active row with the features --features names and no subscription, so a demo tenant
 * can show the staff feature under the resolver that reads the row. The row's language, which the
 * platform's tenant-facing mails read, is --locale, English unless Greek is named. Validates its
 * input at the boundary, hands the provisioner a signup, and ends by printing the hosted page URL
 * and the embed snippet, so the first thing a developer sees after provisioning is the thing a
 * tenant would paste.
 */
final class ProvisionTenantCommand extends Command
{
    /**
     * The line a run adds where it finished a row a failed run had left provisioning, rather than
     * creating one.
     */
    public const string FINISHED = 'An earlier run had stopped part way; this run finished it.';

    /** The sentence refusing a --locale outside the two languages. */
    public const string BAD_LOCALE = 'The locale must be en or el.';

    protected $signature = 'tenant:provision
        {slug : The subdomain label; checked for shape, the reserved list and uniqueness}
        {--name= : The business name, which also names the implicit staff member}
        {--timezone= : The tenant\'s IANA timezone, its one clock}
        {--currency= : The tenant\'s three-letter currency code, upper case}
        {--owner-name= : The owner\'s name}
        {--owner-email= : The owner\'s email, their login at this tenant}
        {--owner-password= : The owner\'s initial password}
        {--features= : Comma-separated feature slugs to write on the new row, such as staff; none leaves it on the floor}
        {--locale=en : The language the platform writes to the tenant\'s people in, en or el}';

    protected $description = 'Provision a tenant, the development and demo path that bypasses billing: the central row active with the features named and no subscription, its schema and migrations, the settings row, the implicit staff member and the owner user. Safe to run twice.';

    public function handle(TenantProvisioner $provisioner, TenantUrls $urls): int
    {
        $input = [
            'slug' => $this->stringArgument('slug'),
            'name' => $this->stringOption('name'),
            'timezone' => $this->stringOption('timezone'),
            'currency' => $this->stringOption('currency'),
            'owner-name' => $this->stringOption('owner-name'),
            'owner-email' => $this->stringOption('owner-email'),
            'owner-password' => $this->stringOption('owner-password'),
            'locale' => $this->stringOption('locale'),
        ];

        $validator = Validator::make($input, [
            'slug' => ['required', 'string'],
            'name' => ['required', 'string'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'owner-name' => ['required', 'string'],
            'owner-email' => ['required', 'email'],
            'owner-password' => ['required', 'string'],
            'locale' => ['required', Rule::enum(SiteLanguage::class)],
        ], [
            'currency.regex' => 'The currency must be three upper-case letters.',
            'locale.*' => self::BAD_LOCALE,
        ], [
            'owner-name' => 'owner name',
            'owner-email' => 'owner email',
            'owner-password' => 'owner password',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $features = $this->features();
        if (is_string($features)) {
            $this->error($features);

            return self::INVALID;
        }

        $signup = new Signup(
            slug: $input['slug'],
            name: $input['name'],
            timezone: new DateTimeZone($input['timezone']),
            currency: $input['currency'],
            ownerName: $input['owner-name'],
            ownerEmail: $input['owner-email'],
            ownerPassword: $input['owner-password'],
            entitlements: $features,
            locale: SiteLanguage::from($input['locale']),
        );

        try {
            $provisioned = $provisioner->provision($signup, $this->output);
        } catch (InvalidSlug $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (ProvisioningRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tenant = $provisioned->tenant;
        $slug = Slug::of($tenant->slug);

        $this->info($provisioned->alreadyActive
            ? sprintf('Tenant "%s" is already active; nothing changed.', $tenant->slug)
            : sprintf('Tenant "%s" is active.', $tenant->slug));

        if (! $provisioned->created && ! $provisioned->alreadyActive) {
            $this->line(self::FINISHED);
        }

        $this->line('Hosted page: '.$urls->hostedPage($slug));
        $this->line('Embed snippet:');
        $this->line($urls->embedSnippet($slug));

        return self::SUCCESS;
    }

    /**
     * The entitlement set --features names, or the sentence refusing the first slug this build
     * does not know. A slug is refused rather than dropped: the row would say less than the
     * developer asked for, silently.
     */
    private function features(): Entitlements|string
    {
        $slugs = array_values(array_filter(array_map(trim(...), explode(',', $this->stringOption('features'))), fn (string $slug): bool => $slug !== ''));

        foreach ($slugs as $slug) {
            if (Feature::tryFrom($slug) === null) {
                return sprintf('"%s" is not a feature this build knows; the features are: %s.', $slug, implode(', ', array_map(fn (Feature $feature): string => $feature->value, Feature::cases())));
            }
        }

        return Entitlements::fromSlugs($slugs);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
