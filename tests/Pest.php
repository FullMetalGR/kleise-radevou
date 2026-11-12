<?php

use App\Application\Clock;
use App\Application\Event\BookingConfirmed;
use App\Application\Notification\MessageKey;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\NewConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\ProviderRegistry;
use App\Billing\BillingProvider;
use App\Billing\Checkout;
use App\Billing\Entitlements;
use App\Billing\SnapshotItem;
use App\Billing\SubscriptionSnapshot;
use App\Billing\SubscriptionSync;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditKey;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Lifetime;
use App\Http\AdminStrings;
use App\Http\BookingPage;
use App\Http\ConsoleSession;
use App\Http\Controllers\MyBookingsController;
use App\Http\Requests\CoverRequest;
use App\Http\Requests\GalleryPhotoRequest;
use App\Http\Requests\LogoRequest;
use App\Http\Requests\PasswordRule;
use App\Http\Requests\SignupRequest;
use App\Http\Requests\StaffPhotoRequest;
use App\Http\SignupStrings;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\CustomerAccess;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Events\TransitionPublishers;
use App\Infrastructure\PaymentFactory;
use App\Notifications\TenantClock;
use App\Notifications\TenantTime;
use App\Platform\Operator;
use App\Platform\PlatformAuditKey;
use App\Site\SiteLanguage;
use App\Tenancy\Provisioned;
use App\Tenancy\SchemaSwitch;
use App\Tenancy\Signup;
use App\Tenancy\SignupVerification;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantMigrator;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Dom\HTMLDocument;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Browser\BrowserTestCase;
use Tests\Concurrency\ConcurrencyTestCase;
use Tests\Concurrency\Harness\Outcome;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeDisks;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;
use Tests\Support\TemporaryFiles;
use Tests\TestCase;

/*
 * Unit tests stay on PHPUnit's own TestCase: no database and no application.
 *
 * Feature tests boot the application. Transaction wrapping is opt-in per file with
 * pest()->use(RefreshDatabase::class) and is never applied here, because a test that enters
 * the transaction runner must not already be inside a transaction.
 *
 * Concurrency tests boot the application, truncate between tests, and are never wrapped.
 *
 * Browser tests do the same, and drive a real browser at the application served from the test
 * process; see BrowserTestCase for the host they serve it on.
 */
// The suspended tenant's page walk, tenantRoutes, tenantRouteSample and pausedPageWalk, lives
// beside the shared fixtures and is loaded here, so every suite and every parallel worker holds it.
require_once __DIR__.'/Support/page-walk.php';

pest()->extend(TestCase::class)->afterEach(fn () => afterBootedTest())->in('Feature');
pest()->extend(ConcurrencyTestCase::class)->afterEach(fn () => afterBootedTest())->in('Concurrency');
pest()->extend(BrowserTestCase::class)->afterEach(fn () => afterBootedTest())->in('Browser');

/*
 * What runs after every test of the three suites that boot the application: the two trails' nets,
 * then the removal of every temporary file the test's fixtures made (TemporaryFiles) and of every
 * disk it faked (FakeDisks), which run whether or not a net fails, so a run leaves the temporary
 * directory as it found it and no storage/framework/testing/disks behind. One hook calls them
 * all, since an extend holds one afterEach.
 */
function afterBootedTest(): void
{
    try {
        auditNets();
    } finally {
        try {
            TemporaryFiles::removeAll();
        } finally {
            FakeDisks::removeAll();
        }
    }
}

/*
 * The two trails' nets: the tenant trail's, then the platform audit log's.
 */
function auditNets(): void
{
    auditTrailNet();
    platformAuditNet();
}

/*
 * The audit trail's two standing rules, run after every test of every suite that boots the
 * application. A transition test writes rows and asserts what it meant to; these read
 * whatever is there and fail on what nobody meant to write.
 *
 * Hooked onto the extends above rather than onto a test case, because a hook declared there
 * runs before a file's own afterEach, and several files drop their tenant schemas in one: a
 * check running after those would read an empty database and prove nothing.
 *
 * The cost on a test that touched no tenant is a single catalog query.
 */
function auditTrailNet(): void
{
    try {
        DB::select('select 1');
    } catch (Throwable) {
        // A test that deliberately trips a constraint leaves its wrapping transaction aborted,
        // and every statement after that fails with 25P02 until it ends. There is nothing to
        // read there and nothing to miss: whatever such a test wrote rolls back with it.
        return;
    }

    expect(auditOffences())->toBe([]);
}

/**
 * Every schema in this database holding an audit_log. The underscore is escaped: unescaped it
 * is a single-character wildcard and would match a schema named "tenantx".
 *
 * @return list<string>
 */
function auditedSchemas(): array
{
    /** @var list<string> $schemas */
    $schemas = DB::table('information_schema.tables')
        ->where('table_name', 'audit_log')
        ->where('table_schema', 'like', 'tenant\\_%')
        ->orderBy('table_schema')
        ->pluck('table_schema')
        ->all();

    return $schemas;
}

/**
 * What the trail carries that it must not: a payload key outside the declared vocabulary, and
 * anything in an actor or a payload that reads as a customer of the same schema.
 *
 * The customer's distinctive strings are read from the bookings of that schema rather than
 * named by the caller, so the assertion holds over any tenant and any test without being told
 * who booked. Values under three characters are skipped, since a one-character phone would
 * match half the vocabulary and prove nothing.
 *
 * @return list<string>
 */
function auditOffences(): array
{
    $offences = [];

    foreach (auditedSchemas() as $schema) {
        $secrets = [];

        foreach (DB::select(sprintf('select customer_name, customer_email, customer_phone from %s.bookings', $schema)) as $row) {
            assert($row instanceof stdClass);

            foreach ([$row->customer_name, $row->customer_email, $row->customer_phone] as $value) {
                if (is_string($value) && strlen($value) >= 3) {
                    $secrets[$value] = $value;
                }
            }
        }

        foreach (DB::select(sprintf('select id, actor, action, payload::text as payload from %s.audit_log order by id', $schema)) as $row) {
            assert($row instanceof stdClass && is_int($row->id) && is_string($row->actor) && is_string($row->action) && is_string($row->payload));

            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

            foreach (array_keys($payload) as $key) {
                if (AuditKey::tryFrom((string) $key) === null) {
                    $offences[] = sprintf('%s audit row %d, action %s: the payload key "%s" is outside the declared list.', $schema, $row->id, $row->action, $key);
                }
            }

            foreach ($secrets as $secret) {
                if (stripos($row->actor, $secret) !== false || stripos($row->payload, $secret) !== false) {
                    $offences[] = sprintf('%s audit row %d, action %s: the trail names the customer, "%s".', $schema, $row->id, $row->action, $secret);
                }
            }
        }
    }

    return $offences;
}

/*
 * The platform audit log's standing rules, run after every test of the three suites beside the
 * tenant trail's: a payload key outside PlatformAuditKey's cases, and an operator's, a tenant
 * user's or a contact message's sender's email in a row, fail the test that wrote it (the
 * sender's because the archive names a message by id alone). The writer refuses the first
 * already; the net reads whatever reached the table, whoever wrote it.
 *
 * The cost on a test that wrote no row is the one read of the table. A test that deliberately
 * tripped a constraint left its wrapping transaction aborted, and the read fails there with
 * nothing to miss, as the tenant net's first statement does; a test that dropped the table has
 * nothing to read either. Those two states alone answer no offence (25P02 and 42P01): a read that
 * fails any other way, a column the read names that is gone among them, is thrown, since the net
 * would otherwise pass over rows it never read.
 */
function platformAuditNet(): void
{
    expect(platformAuditOffences())->toBe([]);
}

/**
 * What the platform audit log carries that it must not. The addresses are read from the operators,
 * users and contact_messages tables rather than named by the caller, as the tenant net reads the
 * customers' strings from the bookings, so the assertion holds over any test without being told
 * who signed in or wrote in. The actor is operator_id, a bigint, and is read with the rest so an
 * address can hide nowhere in a row.
 *
 * @return list<string>
 */
function platformAuditOffences(): array
{
    try {
        $rows = DB::select('select id, operator_id::text as actor, action, payload::text as payload from platform_audit_log order by id');
    } catch (QueryException $failed) {
        if (in_array(SqlState::codeOf($failed), ['25P02', '42P01'], true)) {
            return [];
        }

        throw $failed;
    }

    if ($rows === []) {
        return [];
    }

    /** @var list<string> $emails */
    $emails = DB::table('operators')->pluck('email')->merge(DB::table('users')->pluck('email'))->merge(DB::table('contact_messages')->pluck('email'))->unique()->values()->all();
    $offences = [];

    foreach ($rows as $row) {
        assert($row instanceof stdClass && is_int($row->id) && is_string($row->actor) && is_string($row->action) && is_string($row->payload));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys($payload) as $key) {
            if (PlatformAuditKey::tryFrom((string) $key) === null) {
                $offences[] = sprintf('platform audit row %d, action %s: the payload key "%s" is outside the declared list.', $row->id, $row->action, $key);
            }
        }

        foreach ($emails as $email) {
            if (stripos($row->actor.' '.$row->action.' '.$row->payload, $email) !== false) {
                $offences[] = sprintf('platform audit row %d, action %s: the row names an operator, a user or a contact message\'s sender, "%s".', $row->id, $row->action, $email);
            }
        }
    }

    return $offences;
}

/*
 * Shared helpers for the suites that boot the application.
 */

/**
 * A mask cache over the framework's null store: every availability read through it is cold.
 * The cold base every warm comparison runs against, and what a test binds when it writes rows
 * behind the cache's back without bumping a revision.
 */
function coldMaskCache(): StaffDayMaskCache
{
    return new StaffDayMaskCache(new CacheRepository(new NullStore));
}

/**
 * What the signup form's terms box posts once ticked, SignupForm.tsx's TERMS_TICKED: one value of
 * the accepted rule's set.
 */
const SIGNUP_TERMS_TICKED = '1';

/**
 * A complete signup form as the central host's form posts it, luna's, with any field overridden:
 * SignupTest's, SignupVerifyTest's and SignupInGreekTest's. The terms' box is ticked, posted as
 * the form's component posts it.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function signupForm(array $overrides = []): array
{
    return [
        'slug' => 'luna',
        'name' => 'Luna Studio',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'owner_name' => 'Alex Owner',
        'owner_email' => 'owner@luna.example',
        'password' => 'correct horse battery staple',
        'category' => 'hair_salon',
        SignupRequest::TERMS => SIGNUP_TERMS_TICKED,
        ...$overrides,
    ];
}

/**
 * A complete signup for a slug, with one detail overridable at a time. The tenant starts on the
 * floor unless $features names the slugs its row is provisioned with, as the console command's
 * --features option does, since the resolver reads the row and nothing answers everything.
 *
 * @param  array<string, string>  $overrides
 * @param  list<string>  $features
 */
function signupFor(string $slug, array $overrides = [], array $features = []): Signup
{
    $details = [
        'name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'ownerName' => 'Alex Owner',
        'ownerEmail' => 'owner@example.com',
        'ownerPassword' => 'correct horse battery staple',
        ...$overrides,
    ];

    return new Signup(
        slug: $slug,
        name: $details['name'],
        timezone: new DateTimeZone($details['timezone']),
        currency: $details['currency'],
        ownerName: $details['ownerName'],
        ownerEmail: $details['ownerEmail'],
        ownerPassword: $details['ownerPassword'],
        entitlements: Entitlements::fromSlugs($features),
    );
}

/**
 * @param  array<string, string>  $overrides
 * @param  list<string>  $features
 */
function provisionTenant(string $slug, array $overrides = [], array $features = []): Provisioned
{
    return app(TenantProvisioner::class)->provision(signupFor($slug, $overrides, $features));
}

/**
 * @return array<string, mixed>
 */
function bookingRow(): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'status' => 'confirmed',
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'total_minor' => 2500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'manage_token_hash' => 'hash',
        'source' => 'embed',
    ];
}

/**
 * A service row with every column the CHECK constraints and the not-null columns need, the
 * defaults left to the table, active unless the test says otherwise.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function serviceRow(string $name, array $overrides = []): array
{
    return [
        'name' => $name,
        'duration_min' => 30,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'status' => 'active',
        ...$overrides,
    ];
}

/**
 * An item naming service 1, which is itemService's row in a schema provisioned fresh: the key
 * on booking_items.service_id, migration 000012, refuses an item naming no row.
 *
 * @return array<string, mixed>
 */
function itemRow(int $bookingId, int $staffId, string $start, string $end): array
{
    return [
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => 1,
        'staff_id' => $staffId,
        'start_utc' => $start,
        'end_utc' => $end,
        'block_start_utc' => $start,
        'block_end_utc' => $end,
        'price_minor' => 2500,
    ];
}

/**
 * The service itemRow names, seeded into a schema whose catalog is otherwise empty. The key on
 * booking_items.service_id, migration 000012, refuses an item naming no row, so a fixture that
 * writes items without going through the catalog seeds this first, right after provisioning,
 * where it takes the id itemRow spells the way the implicit member takes staff id 1.
 */
function itemService(string $schema): int
{
    return DB::table($schema.'.services')->insertGetId(serviceRow('Cut'));
}

/**
 * The identifier of a schema's implicit staff member, the row provisioning inserts for the
 * business itself.
 */
function implicitStaffId(string $schema): int
{
    $id = DB::table($schema.'.staff')->where('is_implicit', true)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * Every column of a live table in the order the catalog holds them. What a test reads to hold
 * a payload whitelist against the schema as it actually stands.
 *
 * @return list<string>
 */
function liveColumns(string $schema, string $table): array
{
    /** @var list<string> $columns */
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', $schema)
        ->where('table_name', $table)
        ->orderBy('ordinal_position')
        ->pluck('column_name')
        ->all();

    return $columns;
}

/**
 * Midnight today in the zone luna keeps, which is the tenant clock every browser assertion
 * about a date reads against.
 */
function athensToday(): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->setTime(0, 0);
}

/**
 * An Athens wall-clock time a number of whole calendar days from today, behind today where the
 * number is negative. A test left on the system clock dates its rows with this and never with
 * a literal: the Browser suite runs a real browser on the real clock, and a literal date there
 * is a date the suite drifts past.
 */
function athensDaysOn(int $days, int $hour = 10, int $minute = 0): DateTimeImmutable
{
    $span = new DateInterval(sprintf('P%dD', abs($days)));

    return ($days < 0 ? athensToday()->sub($span) : athensToday()->add($span))->setTime($hour, $minute);
}

/**
 * The day with the given ISO weekday among the seven days of the second availability window,
 * seven to thirteen days on: seven consecutive days hold exactly one, and it lies wholly in the
 * future whatever the clock reads, so the whole of that day's hours are offered. Whole days on,
 * by the calendar, never by elapsed hours, because a clock change makes those two different
 * numbers.
 */
function firstWindowDay(int $weekday): DateTimeImmutable
{
    $today = athensToday();

    for ($days = 7; $days <= 13; $days++) {
        $candidate = $today->add(new DateInterval(sprintf('P%dD', $days)));

        if ((int) $candidate->format('N') === $weekday) {
            return $candidate;
        }
    }

    throw new LogicException('Seven consecutive days hold every weekday once.');
}

/**
 * A path on the console's host, console. under the central host, spelled by TenantUrls as
 * every console URL is.
 */
function consoleUrl(string $path = ''): string
{
    return app(TenantUrls::class)->console($path);
}

/**
 * The application URL with a path, as a request for the central host.
 */
function centralUrl(string $path = ''): string
{
    $url = config('app.url');

    assert(is_string($url));

    return rtrim($url, '/').$path;
}

/**
 * A path on a subdomain of the central host, as a browser would request it: the label in front
 * of the host the application URL names, port included. The label is not validated, so a test
 * can ask for a host no tenant could have.
 */
function tenantUrl(string $label, string $path = ''): string
{
    return str_replace('://', '://'.$label.'.', centralUrl()).$path;
}

/**
 * A real PNG of one colour, drawn here so no binary fixture is tracked; two colours, two files.
 * The browser walks that upload an image share it: the settings' cover and gallery, a staff photo.
 *
 * @param  int<1, max>  $width
 * @param  int<1, max>  $height
 * @param  int<0, 255>  $red
 * @param  int<0, 255>  $green
 * @param  int<0, 255>  $blue
 */
function drawnPng(int $width, int $height, int $red, int $green, int $blue): string
{
    $path = TemporaryFiles::make('kr-photo', '.png');
    $image = imagecreatetruecolor($width, $height);
    assert($image !== false);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * A tenant's hosted page as the application itself prints it, TenantUrls::hostedPage: the
 * tenant origin's root. The Browser suite pins the central host to the port it serves on, so
 * this is the URL the browser opens there as well as the one the test client requests here.
 */
function hostedPage(string $slug): string
{
    return app(TenantUrls::class)->hostedPage(Slug::of($slug));
}

/*
 * The booking page as a document, shared by the two routes that render it: the hosted page and
 * the manage page. The mount contract is about attributes on one element and children of it,
 * so the page is read as a document rather than as a string, where a substring check would
 * pass a page that put the noscript sentence beside the mount.
 */

function settingsOf(string $slug): Builder
{
    return DB::table(sprintf('tenant_%s.settings', $slug))->where('id', 1);
}

/**
 * @param  TestResponse<Response>  $response
 */
function documentOf(TestResponse $response): DOMDocument
{
    $document = new DOMDocument;
    $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR);

    return $document;
}

function mountOf(DOMDocument $document): DOMElement
{
    $mounts = (new DOMXPath($document))->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' kr-booking ')]");

    expect($mounts)->toBeInstanceOf(DOMNodeList::class)->toHaveCount(1);

    $mount = $mounts[0] ?? null;

    assert($mount instanceof DOMElement);

    return $mount;
}

/**
 * @return array<mixed>
 */
function configOf(DOMDocument $document): array
{
    $script = $document->getElementById('kr-config');

    expect($script)->toBeInstanceOf(DOMElement::class)
        ->and($script?->tagName)->toBe('script')
        ->and($script?->getAttribute('type'))->toBe('application/json');

    $config = json_decode((string) $script?->textContent, true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($config));

    return $config;
}

/**
 * The public name, config('site.name'), which the site's copy fills :name with.
 */
function siteName(): string
{
    $name = config('site.name');

    assert(is_string($name));

    return $name;
}

/**
 * A page of the marketing site as served, parsed as the HTML5 document a browser reads, Greek
 * text included, which the older parser reads as Latin-1.
 *
 * The page must be valid UTF-8, and is handed to the parser with every character past ASCII as
 * its numeric reference, which the parser reads back as the same character in text, in a title,
 * a textarea, a noscript and an attribute alike. PHP 8.4.1's HTML5 parser drops the first byte
 * of a two-byte character that straddles a 4096-byte boundary of its input, so a Greek page read
 * as served loses a letter wherever one falls there, which the browser never does.
 *
 * @param  TestResponse<Response>  $response
 */
function siteDocument(TestResponse $response): HTMLDocument
{
    $content = (string) $response->getContent();

    if (! mb_check_encoding($content, 'UTF-8')) {
        throw new LogicException('The page is no valid UTF-8.');
    }

    return HTMLDocument::createFromString(mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), LIBXML_NOERROR);
}

/**
 * The trimmed text of every element a selector names in a page of the site, in document
 * order.
 *
 * @return list<string>
 */
function siteTexts(HTMLDocument $document, string $selector): array
{
    $texts = [];

    foreach ($document->querySelectorAll($selector) as $element) {
        $texts[] = trim((string) $element->textContent);
    }

    return $texts;
}

/**
 * One sentence of the site's copy in a language as a page prints it, the public name filled in
 * with whatever else the key names.
 *
 * @param  array<string, string>  $replace
 */
function siteSentence(string $key, SiteLanguage $language, array $replace = []): string
{
    $value = __($key, ['name' => siteName(), ...$replace], $language->value);

    assert(is_string($value) && $value !== $key, sprintf('%s is no sentence in %s.', $key, $language->value));

    return $value;
}

/**
 * One sentence of the signup's file in a language, its key under signup., with the code's three
 * constants filled in where the sentence names one: the slug's longest length and the password's
 * floor and ceiling.
 */
function signupSentence(string $key, SiteLanguage $language = SiteLanguage::English): string
{
    return siteSentence('signup.'.$key, $language, ['max' => (string) Slug::MAX_LENGTH, ...PasswordRule::fills()]);
}

/**
 * A list of sentences of the site's copy in a language, the public name filled in and whatever
 * else the replacements name.
 *
 * @param  array<string, string>  $replace
 * @return list<string>
 */
function siteSentences(string $key, SiteLanguage $language, array $replace = []): array
{
    $values = __($key, ['name' => siteName(), ...$replace], $language->value);

    assert(is_array($values) && $values !== [] && array_is_list($values), sprintf('%s is no list in %s.', $key, $language->value));

    return array_map(fn (mixed $value): string => is_string($value) ? $value : throw new LogicException(sprintf('%s holds a value that is no string.', $key)), $values);
}

/**
 * The keys of a map of the site's copy in a language, in the file's order: the groups, the
 * questions or the sections a page renders one by one.
 *
 * @return list<string>
 */
function siteCopyKeys(string $key, SiteLanguage $language): array
{
    $values = __($key, [], $language->value);

    assert(is_array($values) && $values !== [], sprintf('%s is no map in %s.', $key, $language->value));

    return array_map(strval(...), array_keys($values));
}

/**
 * One sentence a tenant host's server page prints, a key of pages.php as the constant that held
 * the sentence now holds it, read through the translator in the language named, English unless
 * named, with the public name and whatever else the key names filled in.
 *
 * @param  array<string, string>  $replace
 */
function pageSentence(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    return siteSentence($key, $language, $replace);
}

/**
 * The same for a line read as a choice: the case the count chooses, :count the count unless the
 * replacements name it.
 *
 * @param  array<string, string>  $replace
 */
function pageChoice(string $key, int $count, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    $value = trans_choice($key, $count, $replace, $language->value);

    assert($value !== $key, sprintf('%s is no line in %s.', $key, $language->value));

    return $value;
}

/**
 * The trial's length as the site's copy fills it, :count its days from the code's constant.
 *
 * @return array{count: string}
 */
function siteTrialDays(): array
{
    return ['count' => (string) TenantProvisioner::trialDays()];
}

/**
 * One line of the platform's mails read as a choice, a key of mail.php: the case the count
 * chooses, :count the count as digits, the public name filled in as the mail fills it (the
 * links' windows by their hours and the trial by its days).
 */
function mailChoice(string $key, int $count, SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'mail.'), sprintf('%s is no key of mail.php.', $key));

    return pageChoice($key, $count, ['name' => siteName()], $language);
}

/**
 * One sentence of the API's refusals, a key of api.php as the constant that held the sentence now
 * holds it, read through the translator in the language named, English unless named, with
 * whatever the key names filled in: :n an item's position.
 *
 * @param  array<string, string>  $replace
 */
function apiSentence(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'api.'), sprintf('%s is no key of api.php.', $key));

    return siteSentence($key, $language, $replace);
}

/**
 * The same for a line read as a choice, a limit the code's constant names: the case the count
 * chooses, :count the count.
 */
function apiChoice(string $key, int $count, SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'api.'), sprintf('%s is no key of api.php.', $key));

    return pageChoice($key, $count, [], $language);
}

/**
 * The keys of api.php that tests/Feature/Language/AdminMoveLanguageTest.php walks on the demo
 * tenant rather than ApiLanguageTest on a bare one: the admin's move, whose write enters the
 * transaction runner and so cannot run inside the wrapping transaction ApiLanguageTest holds, and
 * the hold of a blocked address, which needs a customer's profile. Both walks hold their keys to
 * this list, so the two together are api.php's.
 *
 * @return list<string>
 */
function apiKeysWalkedOnTheDemo(): array
{
    return [
        'envelope.unaccepted_warning', 'envelope.blocked',
        'move.past', 'move.off_grid', 'move.service_gone', 'move.staff_gone', 'move.staff_not_eligible', 'move.no_such_segment',
        'validation.move.date', 'validation.move.one', 'validation.move.staff', 'validation.move.warning_accepted', 'validation.move.tell_customer',
    ];
}

/**
 * The keys of a file under lang whose line, in the language named, a text shows (the walk by
 * keys, as resources/js/bookingWords.ts reads booking.php): every case of a choice, its
 * condition stripped, each :word free to be filled with anything. A line the two languages hold
 * alike is a shared value and is left out. Sorted, so a walk compares the keys a page shows with
 * the keys it should, and an English walk of a Greek page finds none.
 *
 * @return list<string>
 */
function linesShownIn(string $text, string $file, SiteLanguage $language): array
{
    $other = $language === SiteLanguage::English ? SiteLanguage::Greek : SiteLanguage::English;
    $lines = Arr::dot((array) require lang_path($language->value.'/'.$file.'.php'));
    $twins = Arr::dot((array) require lang_path($other->value.'/'.$file.'.php'));
    $shown = [];

    foreach ($lines as $key => $line) {
        if (! is_string($line) || ($twins[$key] ?? null) === $line) {
            continue;
        }

        foreach (explode('|', $line) as $form) {
            $parts = preg_split('/:[A-Za-z][A-Za-z0-9_]*/', trim((string) preg_replace('/^\s*[{[][^}\]]*[}\]]/', '', $form))) ?: [];
            $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'/u';

            if (preg_match($pattern, $text) === 1) {
                $shown[] = (string) $key;

                break;
            }
        }
    }

    sort($shown);

    return $shown;
}

/**
 * Every :word a text leaves unfilled, a time's minutes and an address's port left alone.
 *
 * @return list<string>
 */
function unfilledIn(string $text): array
{
    preg_match_all('/(?<![A-Za-z0-9_:\/]):[A-Za-z][A-Za-z0-9_]*/', $text, $unfilled);

    return $unfilled[0];
}

/**
 * What a page shows a reader, for a walk by keys: its text, the title's included, with its
 * scripts and styles taken out, and the labels it carries, alt, aria-label, title and
 * placeholder, each run of white space read as one space.
 *
 * @param  TestResponse<Response>  $response
 */
function shownTextOf(TestResponse $response): string
{
    $document = documentOf($response);
    $xpath = new DOMXPath($document);

    foreach (iterator_to_array($xpath->query('//script | //style') ?: []) as $node) {
        if ($node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);
        }
    }

    $texts = [(string) $document->documentElement?->textContent];

    foreach ($xpath->query('//@alt | //@aria-label | //@title | //@placeholder') ?: [] as $attribute) {
        $texts[] = (string) $attribute->nodeValue;
    }

    return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $texts)));
}

/**
 * The values the two languages share by nature: the name, the domain, the contact address, the
 * processors' names, a proper noun the files use, each a whole value the Greek file may hold as
 * the English does. The site's files hold none as a whole value, legal/ included: the name and
 * the address are placeholders, and the processors' and the cookies' names sit inside sentences.
 * Each value is added with its reason. A sentence never joins it. LanguageFilesTest's
 * translation test reads it, and so do the walks by keys, each of which may run alone.
 *
 * @return list<string>
 */
function languageSharedValues(): array
{
    return [
        // booking.php's details.email, decision.php's facts.email, admin/customers.php's
        // table.email and wizard.fields.email, admin/shell.php's login.email and
        // admin/bookings.php's dialog.facts.email, admin/staff.php's table.email, form.email and
        // view.email, and admin/team.php's invite.email and members.email: a form's field label, a
        // booking fact's label and a column head, which Greek writes in Latin letters as the
        // English does.
        'Email',
        // pages.php's business.instagram and business.facebook, admin/shell.php's
        // shared.channels.instagram and .facebook and admin/settings.php's profile.instagram and
        // profile.facebook: the business page's contact pills, a booking's channel and the
        // profile's two link fields naming the two networks, brand names Greek writes in Latin
        // letters.
        'Instagram',
        'Facebook',
        // admin/shell.php's shared.channels.google: a booking's channel naming the search
        // engine, a brand name Greek writes in Latin letters.
        'Google',
        // admin/services.php's table.id and admin/staff.php's table.id: a list's column head for a
        // row's number, the abbreviation Greek interfaces write in Latin letters.
        'ID',
        // admin/services.php's table.online and modes.online: the catalog's mark and the payment
        // mode, the loanword Greek booking tools write in Latin letters for online booking.
        'Online',
        // admin/customers.php's notes.byline, wizard.step, wizard.when, wizard.summary.span and
        // wizard.summary.service_staff, and admin/services.php's staff.window: a layout of
        // placeholders and punctuation alone, no word in it, the same in both languages.
        ':author, :when',
        ':n. :step',
        ':date :time',
        ':time - :end',
        ':service, :staff',
        ':weekday :time-:end',
        // admin/home.php's tiles.duration, admin/staff.php's figure, admin/shifts.php's
        // page.hours_minutes and admin/reports.php's tiles.hours_minutes: a tile's hours and
        // minutes, a member's weekly hours and a figure of the Shifts grid, a layout of
        // placeholders alone, the same in both languages.
        ':hours :minutes',
        // admin/home.php's activity.line and days.day: an activity row's booking and start, and a
        // day's weekday and date, each a layout of placeholders and punctuation alone, the same in
        // both languages.
        ':booking, :start',
        ':weekday :day',
        // admin/staff.php's table.closed_window, form.user_option, closures.window and
        // closures.who_reason: a layout of placeholders and punctuation alone, no word in it, the
        // same in both languages.
        ':day :time-:end',
        ':user (:address)',
        ':date :time-:end',
        ':who, :reason',
        // admin/payments.php's fields.stripe.secret_key.label: the Greek label quotes in brackets
        // the provider's dashboard's own name for the value, so the owner finds it there; the name
        // is English inside the Greek label, never a value of its own.
        'Secret key',
        // admin/settings.php's profile.country_option, hours.day, hours.window, and hours.separator
        // and validation.list.separator: a country's name with its code in brackets, a roster day's
        // weekday before its windows, an opening window's two times, and the comma that joins the
        // windows of a day and the items of a list, each a layout of placeholders and punctuation
        // alone, the same in both languages.
        ':country (:code)',
        ':day: :windows',
        ':start-:end',
        ', ',
        // admin/shifts.php's page.day_head and admin/calendar.php's toolbar.day_title, a day's ICU
        // weekday before its date; admin/shifts.php's editor.title, the day editor's name of the
        // member before the day; admin/calendar.php's grid.block, a booking block's customer and
        // service, and day.menu, a Day column menu's member and date: layouts of placeholders and
        // punctuation alone, no word in them, the same in both languages.
        ':weekday :date',
        ':name, :weekday :date',
        ':customer, :service',
        ':staff, :date',
        // admin/billing.php's checkout.option: a plan's name and its price a month in the plan
        // choice's list, a layout of two placeholders and a comma, the same in both languages.
        ':plan, :price',
        // admin/reports.php's csv.in_currency: a column head of the CSV beside its currency's code
        // in brackets, a layout of two placeholders and punctuation, the same in both languages.
        ':column (:currency)',
        // site.php's entity: the legal entity's name before its address in the site's footer, a
        // layout of two placeholders and a comma, the same in both languages.
        ':entity, :address',
    ];
}

/**
 * Every line of a language file by its key read with dots, for a browser walk's script: the
 * booking app's (GreekJourneyTest) and the admin's (AdminGreekTest), each of which may run
 * alone.
 *
 * @param  array<mixed>  $table
 * @return array<string, string>
 */
function greekWalkLines(array $table, string $prefix = ''): array
{
    $lines = [];

    foreach ($table as $name => $value) {
        if (is_array($value)) {
            $lines += greekWalkLines($value, $prefix.$name.'.');
        } elseif (is_string($value)) {
            $lines[$prefix.$name] = $value;
        }
    }

    return $lines;
}

/**
 * One line of the decision pages and the booking facts, a key of decision.php as the constant or
 * the class that held the sentence now holds it, read through the translator in the language
 * named, English unless named, with whatever the key names filled in: :business the tenant,
 * :service and :staff an item's names, :amount the total's amount, :zone the clock.
 *
 * @param  array<string, string>  $replace
 */
function decisionLine(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'decision.'), sprintf('%s is no key of decision.php.', $key));

    return siteSentence($key, $language, $replace);
}

/**
 * One line of the customer's messages, a key of customer-mail.php as the template that held the
 * sentence now names it, read through the translator in the language named, English unless
 * named, with whatever the key names filled in: :customer the name on the booking, :business the
 * tenant, :service the booking's services as the message lists them, :zone the clock.
 *
 * @param  array<string, string>  $replace
 */
function customerMailLine(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'customer-mail.'), sprintf('%s is no key of customer-mail.php.', $key));

    return siteSentence($key, $language, $replace);
}

/**
 * One line of the business's messages, a key of business-mail.php as the template that held the
 * sentence now names it, read through the translator in the language named, English unless
 * named, with whatever the key names filled in: :business the tenant, :customer the name on the
 * booking, :staff the user who acted, :name the platform's public name, :from, :to and :end an
 * instant, :label the approval message's label.
 *
 * @param  array<string, string>  $replace
 */
function businessMailLine(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'business-mail.'), sprintf('%s is no key of business-mail.php.', $key));

    return siteSentence($key, $language, $replace);
}

/**
 * The same for a line read as a choice, the nag's time left by its hours: the case the count
 * chooses, :count the count.
 *
 * @param  array<string, string>  $replace
 */
function businessMailChoice(string $key, int $count, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'business-mail.'), sprintf('%s is no key of business-mail.php.', $key));

    return pageChoice($key, $count, $replace, $language);
}

/**
 * One line of the admin's sections, a key of a file under lang/{en,el}/admin as the constant or
 * the class that held the sentence now holds it, read through the translator in the language
 * named, English unless named, with whatever the key names filled in: :operator support's
 * operator, :end and :zone the support session's end and clock, :n a figure spelled in words.
 *
 * @param  array<string, string>  $replace
 */
function adminLine(string $key, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'admin/'), sprintf('%s is no key of a file under lang/en/admin.', $key));

    return siteSentence($key, $language, $replace);
}

/**
 * The same for a line read as a choice: the case the count chooses, :count the count unless a
 * value names it.
 *
 * @param  array<string, string>  $replace
 */
function adminChoice(string $key, int $count, array $replace = [], SiteLanguage $language = SiteLanguage::English): string
{
    assert(str_starts_with($key, 'admin/'), sprintf('%s is no key of a file under lang/en/admin.', $key));

    return pageChoice($key, $count, $replace, $language);
}

/**
 * The strings prop of a central page in a language: the sections of signup.php the pages read, its
 * waiting sentence chosen by the verification link's hours as SignupStrings chooses it, and under
 * shell the groups of the admin's shell section the signup's pages render through the admin's
 * kit, the toaster and the controls.
 *
 * @return array<mixed>
 */
function centralStrings(SiteLanguage $language): array
{
    $signup = __('signup', [], $language->value);
    $shell = __('admin/shell', [], $language->value);
    assert(is_array($signup) && is_array($shell));
    $signup = array_intersect_key($signup, array_flip(SignupStrings::SECTIONS));
    data_set($signup, SignupStrings::WAITING, pageChoice('signup.'.SignupStrings::WAITING, Lifetime::hours(SignupVerification::LINK_LIFETIME), [], $language));
    assert(is_array($signup));

    return [...$signup, 'shell' => array_intersect_key($shell, array_flip(AdminStrings::KIT))];
}

/**
 * One of the four image uploads' refusals as the request words it, by its key: a size read as a
 * choice by the request's MAX_KILOBYTES in megabytes, :n the figure spelled in words by ICU in the
 * language named, and every other line read as it stands.
 */
function uploadLine(string $key, SiteLanguage $language = SiteLanguage::English): string
{
    $limits = [
        LogoRequest::SIZE => LogoRequest::MAX_KILOBYTES,
        CoverRequest::SIZE => CoverRequest::MAX_KILOBYTES,
        GalleryPhotoRequest::SIZE => GalleryPhotoRequest::MAX_KILOBYTES,
        StaffPhotoRequest::SIZE => StaffPhotoRequest::MAX_KILOBYTES,
    ];

    if (! array_key_exists($key, $limits)) {
        return adminLine($key, [], $language);
    }

    $megabytes = intdiv($limits[$key], 1024);
    $spelled = (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format($megabytes);

    return adminChoice($key, $megabytes, ['n' => is_string($spelled) ? $spelled : (string) $megabytes], $language);
}

/**
 * An entry of the admin's rail as the rail labels it, by its key under the shell's nav, calendar
 * to billing, or a group's under nav.groups: schedule or business.
 */
function navLabel(string $entry, SiteLanguage $language = SiteLanguage::English): string
{
    return adminLine('admin/shell.nav.'.$entry, [], $language);
}

/**
 * Greek lines fabricated for the case alone: each key given the line after GREEK, ASCII as every
 * test file is, and the key's own words in it, so a page or a message that reads the wrong line
 * says which. Laid over the translator's el lines for the length of the test, over a key whose
 * Greek file is still pending or over one whose Greek is written, where a case asks which language
 * answers rather than what the line says. The one helper the language tests share.
 *
 * @param  array<string, string>  $lines  the key and what the line says beside the marker
 */
function greekLines(array $lines): void
{
    app('translator')->addLines(array_map(fn (string $line): string => 'GREEK '.$line, $lines), 'el');
}

/**
 * An instant as a page of luna's host or a message about one of its bookings spells it: ICU's
 * medium date and short time in the language named, English unless named, in Athens on the
 * formats every tenant is provisioned with. TenantTimeTest holds the spelling itself to ICU's
 * English and Greek; this is for a case whose date is relative to today.
 */
function lunaInstant(DateTimeImmutable $instant, SiteLanguage $language = SiteLanguage::English): string
{
    return TenantTime::instant($instant, new TenantClock(new DateTimeZone(FixtureDay::ZONE)), $language);
}

/**
 * Runs the demo seeder from the console and returns everything it printed. The buffer is the
 * caller's own: the seeder provisions through a nested console call that takes over the last
 * output the facade would otherwise hand back.
 */
function seedDemo(): string
{
    $output = new BufferedOutput;
    Artisan::call('db:seed', ['--class' => DemoTenantsSeeder::class, '--no-interaction' => true], $output);

    return $output->fetch();
}

/**
 * Drops the schemas of the demo tenants the Browser suite seeds, which sit outside the
 * truncation that suite runs between tests.
 */
function dropDemoSchemas(): void
{
    foreach (DemoTenantsSeeder::TENANTS as $demo) {
        DB::statement(sprintf('drop schema if exists tenant_%s cascade', $demo['slug']));
    }
}

/**
 * The demo tenant's slug, the configured label site.demo_slug that demo:reset builds the demo
 * under and the home page spells the demo's origin from.
 */
function siteDemoSlug(): Slug
{
    $label = config('site.demo_slug');

    assert(is_string($label));

    return Slug::of($label);
}

/**
 * Drops the demo tenant demo:reset builds under site.demo_slug, which no truncation reaches
 * whole: its schema, and its tenants row with its owner's users row, so a later file wrapped in a
 * transaction never reads them. dropDemoSchemas() drops the seeder's two tenants and leaves this
 * one standing.
 */
function dropSiteDemo(): void
{
    $slug = siteDemoSlug();
    $id = DB::table('tenants')->where('slug', $slug->value)->value('id');

    DB::statement(sprintf('drop schema if exists %s cascade', $slug->schemaName()));

    if ($id !== null) {
        DB::table('users')->where('tenant_id', $id)->delete();
        DB::table('tenants')->where('id', $id)->delete();
    }
}

/**
 * Leaves the tenant of the slug as the drop leaves a tenant thirty days after its close, for a
 * case that needs a closed row and is not about the drop: its schema dropped and its users
 * deleted, its row closed with closed_at now and its slug released. Answers its id. The drop
 * itself is proven in tests/Feature/Retention/TenantDropTest.php.
 */
function writeClosed(string $slug): int
{
    $id = DB::table('tenants')->where('slug', $slug)->value('id');
    assert(is_int($id));

    DB::statement(sprintf('drop schema if exists %s cascade', Slug::of($slug)->schemaName()));
    DB::table('users')->where('tenant_id', $id)->delete();
    DB::update("update tenants set status = 'closed', closing_at = coalesce(closing_at, now() - interval '30 days'), closed_at = now(), slug = null where id = ?", [$id]);

    return $id;
}

/**
 * The array a harness worker returned.
 *
 * @return array<mixed>
 */
function returnedArray(Outcome $outcome): array
{
    $value = $outcome->returned();

    assert(is_array($value));

    return $value;
}

/**
 * A query exception as the pgsql driver raises it: the SQLSTATE in errorInfo and the message in
 * whatever language the cluster speaks. For the code paths that must read the code and never
 * the text, so the default message is deliberately not one the framework would recognise.
 */
function sqlError(string $sqlstate, string $message = 'Verklemmung entdeckt'): QueryException
{
    $cause = new PDOException($message);
    $cause->errorInfo = [$sqlstate, 7, $message];

    return new QueryException('pgsql', 'select 1', [], $cause);
}

/**
 * A backend blocked on a lock inside a statement starting with the prefix, as the activity view
 * reports it: the wait is observed, not inferred from timing. The view is polled, bounded,
 * because a backend blocked on a lock can reach no checkpoint and report nothing itself. The
 * bound stays under the runner's lock timeout of three seconds: past that the blocked backend
 * is aborted with 55P03 and leaves the view, so a longer poll would only wait to fail.
 *
 * @return array{pid: int, wait_event: string}
 */
function lockWaiter(string $prefix, float $seconds = 2.5): array
{
    $deadline = microtime(true) + $seconds;

    do {
        $row = DB::selectOne(
            "select pid, wait_event from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query like ?",
            [$prefix.'%'],
        );

        if ($row instanceof stdClass && is_int($row->pid) && is_string($row->wait_event)) {
            return ['pid' => $row->pid, 'wait_event' => $row->wait_event];
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    Assert::fail(sprintf('No backend was blocked on a lock in a statement starting "%s" within %.1f seconds.', $prefix, $seconds));
}

/**
 * Every lock a backend holds or waits for, as the lock view reports it: the kind, the relation
 * when the lock is on one, and whether it was granted.
 *
 * @return list<array{locktype: string, relation: string|null, granted: bool}>
 */
function locksOf(int $pid): array
{
    $locks = [];

    foreach (DB::select('select locktype, relation::regclass::text as relation, granted from pg_locks where pid = ? order by locktype, relation, granted', [$pid]) as $row) {
        assert($row instanceof stdClass && is_string($row->locktype) && is_bool($row->granted));
        assert($row->relation === null || is_string($row->relation));

        $locks[] = ['locktype' => $row->locktype, 'relation' => $row->relation, 'granted' => $row->granted];
    }

    return $locks;
}

/*
 * Helpers the suites that boot the application share with the Unit suite: an instant from a
 * few words, which every test that reasons about time spells the same way.
 */

/**
 * An instant named by its text and its zone, UTC unless the test says otherwise: the zone is
 * named on the way in, and the reading is the position on the timeline. It keeps its name
 * beside WindowGeometry::instantAt and StaffDayGeometry::instantAt, which answer another
 * question: those are instance methods, and a method cannot collide with a global function.
 */
function instantAt(string $text, string $zone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

/*
 * The notification seam helpers, shared by every notification suite so that two files cannot
 * acquire two ideas of what a sent message is, and so that any one of them runs alone. The
 * classes beside them, SeamLog, RefusingTransport and HaltedClock, live under tests/Support.
 *
 * luna is the tenant every one of them reads: its rows are the demo seeder's, its customer is
 * LunaFixture's, and its transport is the array transport phpunit.xml names. tenantUserId alone
 * takes the address of another tenant's user, for the test that refuses one a link.
 */

function seamMailer(): Mailer
{
    return app('mailer');
}

function seamTransport(): ArrayTransport
{
    $transport = seamMailer()->getSymfonyTransport();

    assert($transport instanceof ArrayTransport);

    return $transport;
}

/**
 * Every message the array transport collected, as the mail objects they are.
 *
 * @return list<Email>
 */
function seamSent(): array
{
    $sent = [];

    foreach (seamTransport()->messages() as $message) {
        assert($message instanceof SentMessage);

        $original = $message->getOriginalMessage();

        assert($original instanceof Email);

        $sent[] = $original;
    }

    return $sent;
}

function seamOnly(): Email
{
    $sent = seamSent();

    expect($sent)->toHaveCount(1);

    return $sent[0];
}

/**
 * The id of a row of luna's catalog by its name.
 */
function seamId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A hold as the booking page sends one, on luna, for the named service on Elena: the URL and
 * the body, for a test to post itself.
 *
 * @return array{string, array<string, mixed>}
 */
function seamHold(string $service = 'Bridal trial', string $start = FixtureDay::START): array
{
    return [tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Perpetua Vandersnoot',
        'customer_email' => 'perpetua.vandersnoot@example.test',
        'customer_phone' => '+30 210 1234567',
        'items' => [['service_id' => seamId('services', $service), 'staff_id' => seamId('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ]];
}

/**
 * @param  TestResponse<Response>  $response
 * @return array{uuid: string, token: string}
 */
function seamHeld(TestResponse $response): array
{
    $response->assertCreated();

    $uuid = $response->json('uuid');
    $token = $response->json('manage_token');

    assert(is_string($uuid) && is_string($token));

    return ['uuid' => $uuid, 'token' => $token];
}

/**
 * Every key of the catalog but the ones named, which is how a test switches off everything
 * except the message it is asking about.
 *
 * @return list<string>
 */
function seamEveryKeyBut(MessageKey ...$kept): array
{
    $keys = array_map(fn (MessageKey $key): string => $key->value, MessageKey::cases());

    return array_values(array_diff($keys, array_map(fn (MessageKey $key): string => $key->value, $kept)));
}

/**
 * Switches off the keys given, as the tenant's settings row stores them: the negative, so a key
 * absent from the list sends.
 *
 * @param  list<string>  $keys
 */
function seamSwitchOff(array $keys): void
{
    // Bound rather than spelled into the statement: the list is built from the catalog, so it
    // is a value and not a literal, and a text[] takes one through a cast like any other type.
    DB::statement('update tenant_luna.settings set emails_off = ?::text[] where id = 1', ['{'.implode(',', $keys).'}']);
}

/**
 * The one message sent to an address, and the assertion that there was exactly one.
 */
function seamAt(string $address): Email
{
    $sent = array_values(array_filter(
        seamSent(),
        fn (Email $message): bool => array_map(fn ($to): string => $to->getAddress(), $message->getTo()) === [$address],
    ));

    expect($sent)->toHaveCount(1);

    return $sent[0];
}

/**
 * Every address a message went to, in the order they were sent.
 *
 * @return list<string>
 */
function seamAddresses(): array
{
    $addresses = [];

    foreach (seamSent() as $message) {
        foreach ($message->getTo() as $to) {
            $addresses[] = $to->getAddress();
        }
    }

    return $addresses;
}

/**
 * The confirm as the booking app sends it, with the token in the body, where a POST carries
 * it.
 */
function seamConfirm(TestCase $test, string $uuid, string $token): void
{
    $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/confirm'), ['token' => $token])->assertOk();
}

/**
 * A confirmation announced for a booking that is already there, settled from the hold class
 * given, which is how the paths not reached over the wire here are put on trial: an
 * approval an owner granted, and the manual booking with no hold at all.
 */
function seamAnnounceConfirmed(string $uuid, ?HoldClass $class, ?string $token = null): void
{
    app(TransitionPublishers::class)
        ->for(Tenant::query()->where('slug', 'luna')->sole())
        ->publish(new BookingConfirmed($uuid, BookingStatus::AwaitingApproval, BookingStatus::Confirmed, $class, Actor::customer(), $token));
}

/**
 * Another user of luna, optionally linked to one of its staff rows, which is how a person both
 * holds hours and signs in. Named by its address so a test reads who it made.
 */
function addTenantUser(string $email, string $role, ?string $staff = null): int
{
    $tenant = DB::table('tenants')->where('slug', 'luna')->value('id');

    assert(is_int($tenant));

    $id = DB::table('users')->insertGetId([
        'tenant_id' => $tenant,
        'name' => $email,
        'email' => $email,
        'password' => bcrypt('correct horse battery staple'),
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($staff !== null) {
        DB::table('tenant_luna.staff')->where('name', $staff)->update(['user_id' => $id]);
    }

    return $id;
}

/**
 * The id of a user by address: luna's owner as the demo seeder made it unless the test names
 * another, which is the user a business-side act is performed as and the one a decision link
 * is minted for.
 */
function tenantUserId(string $email = 'owner@luna.example'): int
{
    $id = DB::table('users')->where('email', $email)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * The one message sent to an address, as text: seamAt's body, for the tests that read words.
 */
function seamTextAt(string $address): string
{
    return (string) seamAt($address)->getTextBody();
}

/**
 * The last message the transport took, as the mailer encoded it for the wire: its Subject header
 * as written, that header decoded with the white space between an encoded word and plain text
 * kept, and its text part decoded from its transfer encoding.
 *
 * @return array{header: string, subject: string, text: string}
 */
function mailAsSent(): array
{
    $sent = seamTransport()->messages()->last();
    assert($sent instanceof SentMessage);
    $raw = $sent->toString();
    [$head] = explode("\r\n\r\n", $raw, 2);
    preg_match('/^Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m', $head, $subject);
    preg_match('/Content-Type: text\/plain; charset=utf-8\r\nContent-Transfer-Encoding: ([a-z0-9-]+)\r\n\r\n(.*?)(?=\r\n--|\z)/s', $raw, $part);
    $text = match ($part[1] ?? null) {
        'quoted-printable' => quoted_printable_decode($part[2]),
        'base64' => (string) base64_decode($part[2], true),
        default => $part[2] ?? '',
    };

    return ['header' => $subject[1] ?? '', 'subject' => mb_decode_mimeheader($subject[1] ?? ''), 'text' => $text];
}

/**
 * Asserts the message the transport took last is the one given, a mail whose subject holds
 * text beyond ASCII (Greek, say) intact on the wire: the subject encoded by the
 * mailer into an ASCII header that decodes to it, and the text part valid UTF-8 that decodes to
 * its text.
 */
function mailSentIntact(Email $mail): void
{
    $sent = mailAsSent();

    expect(preg_match('/\A[\x20-\x7e\r\n\t]+\z/', $sent['header']))->toBe(1)
        ->and($sent['header'])->not->toBe($mail->getSubject())
        // A fold's white space meets the space an encoded word ends on, and decoders read the
        // pair apart, so the words are compared and every run of white space read as one.
        ->and(mb_check_encoding($sent['subject'], 'UTF-8'))->toBeTrue()
        ->and(preg_replace('/\s+/u', ' ', $sent['subject']))->toBe($mail->getSubject())
        ->and(mb_check_encoding($sent['text'], 'UTF-8'))->toBeTrue()
        ->and(str_replace("\r\n", "\n", $sent['text']))->toBe(str_replace("\r\n", "\n", (string) $mail->getTextBody()));
}

/**
 * Every address a message went to, sorted: seamAddresses as a set, for a test that names who
 * was written to rather than in what order.
 *
 * @return list<string>
 */
function seamAddressSet(): array
{
    $addresses = seamAddresses();

    sort($addresses);

    return $addresses;
}

/**
 * Every link in a message, in the order they appear.
 *
 * @return list<string>
 */
function seamLinksIn(string $body): array
{
    preg_match_all('#https?://\S+#', $body, $found);

    return $found[0];
}

/**
 * A calendar file's SEQUENCE line for a revision, ended as RFC 5545 ends every line, CRLF: the
 * one spelling a file's contents are held to, so SEQUENCE:1 never passes for SEQUENCE:12.
 */
function icsSequenceLine(int $revision): string
{
    return 'SEQUENCE:'.$revision."\r\n";
}

/**
 * The billing port as the container answers it, typed as the port: the static analyser reads the
 * application's own binding and not the fake a test binds over it.
 */
function billingPort(): BillingProvider
{
    return app(BillingProvider::class);
}

/**
 * The fake billing provider the test environment binds, BILLING_PROVIDER in phpunit.xml, taken
 * back out of the container so a test can script it and read what it was asked.
 */
function billingFake(): FakeBillingProvider
{
    $fake = billingPort();

    return $fake instanceof FakeBillingProvider ? $fake : throw new LogicException('The fake billing provider is not bound.');
}

/**
 * A subscription as the provider would report it, one of each price given, its period ending at
 * a fixed instant spelled in UTC.
 *
 * @param  array<mixed>  $prices
 */
function billingSnapshot(string $subscriptionId, string $customerId, string $status, array $prices, string $periodEnd = '2026-10-29 09:00'): SubscriptionSnapshot
{
    return new SubscriptionSnapshot(
        $subscriptionId,
        $customerId,
        $status,
        new DateTimeImmutable($periodEnd, new DateTimeZone('UTC')),
        array_values(array_map(fn (mixed $price): SnapshotItem => new SnapshotItem(is_string($price) ? $price : throw new LogicException('A price identifier is text.'), 1), $prices)),
    );
}

/**
 * The subscription sync as the container builds it, with a logger a test reads where one is given.
 */
function syncWith(?SeamLog $log = null): SubscriptionSync
{
    return app()->make(SubscriptionSync::class, $log === null ? [] : ['log' => $log]);
}

/**
 * The billing columns of a tenant row, spelled as PostgreSQL hands them back.
 *
 * @return array<mixed>
 */
function billingColumns(string $slug = 'luna'): array
{
    return (array) DB::selectOne(
        "select entitlements, narrowed, plan_ref, subscription_state, to_char(grace_ends_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as grace_ends_at from tenants where slug = ?",
        [$slug],
    );
}

/**
 * @return array<mixed>
 */
function subscriptionColumns(string $slug = 'luna'): array
{
    return (array) DB::selectOne(
        "select s.provider_customer_id, s.provider_subscription_id, s.status, to_char(s.current_period_end at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as current_period_end, s.items::text as items
         from subscriptions s join tenants t on t.id = s.tenant_id where t.slug = ?",
        [$slug],
    );
}

/**
 * The checkout a signup opened as the subscriptions row records it: the session, where it sends
 * the person, and the instant it opened in UTC, each null once the record is cleared.
 *
 * @return array<mixed>
 */
function checkoutRecord(string $slug = 'luna'): array
{
    return (array) DB::selectOne(
        "select s.checkout_session_id, s.checkout_url, to_char(s.checkout_opened_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as checkout_opened_at
         from subscriptions s join tenants t on t.id = s.tenant_id where t.slug = ?",
        [$slug],
    );
}

/**
 * The revision the tenant's subscriptions row stands at, as a caller reads it before asking the
 * provider and hands it to the sync.
 */
function subscriptionRevision(string $slug = 'luna'): int
{
    $revision = DB::scalar('select s.revision from subscriptions s join tenants t on t.id = s.tenant_id where t.slug = ?', [$slug]);

    return is_int($revision) ? $revision : throw new LogicException("{$slug} has no subscriptions row.");
}

/**
 * A checkout as the port answers it for the customer, with a session and a URL of its own.
 */
function openedCheckout(string $customerId): Checkout
{
    return new Checkout($customerId, 'cs_'.$customerId, 'https://checkout.test/'.$customerId);
}

/**
 * @param  array<string, string|null>  $columns
 */
function billingSet(array $columns, string $slug = 'luna'): void
{
    DB::table('tenants')->where('slug', $slug)->update($columns);
}

/**
 * A tenant row with no schema behind it and the provider's customer cus_{slug} recorded, as
 * signup leaves it before the checkout: all the subscription sync reads and writes.
 */
function billingTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);
    syncWith()->attachCustomer($tenant, openedCheckout('cus_'.$slug));

    return $tenant;
}

/**
 * The schema of a tenant row made without one, migrated and its settings row written as
 * provisioning leaves them: for a row whose mail or request reads the tenant's language, which
 * its settings hold.
 */
function schemaBehind(Tenant $tenant): Tenant
{
    $connection = DB::connection();

    // In a transaction of its own, which the schema's switch needs where the test opened none.
    $connection->transaction(function () use ($connection, $tenant): void {
        $connection->statement(sprintf('create schema if not exists "%s"', $tenant->schema_name));

        SchemaSwitch::within($connection, $tenant->schema_name, function () use ($connection, $tenant): void {
            app(TenantMigrator::class)->migrate($tenant->schema_name);
            $connection->table('settings')->insertOrIgnore(['id' => 1]);
        });
    });

    return $tenant;
}

/**
 * A notification posted to the webhook as the provider would post it: the raw body and its
 * signature in the header the port reads, signed with the fake's secret unless a test says
 * otherwise.
 *
 * @return TestResponse<Response>
 */
function deliverBillingNotice(TestCase $test, string $payload, ?string $signature = null, string $url = ''): TestResponse
{
    $header = 'HTTP_'.strtoupper(str_replace('-', '_', billingPort()->signatureHeader()));

    return $test->call('POST', $url === '' ? centralUrl('/webhooks/billing') : $url, [], [], [], [
        $header => $signature ?? FakeBillingProvider::sign($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/**
 * The questions the fake was asked, less the verification every delivery begins with.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function billingPortReads(): array
{
    return array_values(array_filter(billingFake()->asked, fn (array $question): bool => $question[0] !== 'verify'));
}

/**
 * @return array<mixed>
 */
function billingWebhookEvent(string $eventId): array
{
    return (array) DB::selectOne(
        "select provider_event_id, type, payload::text as payload, to_char(processed_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as processed_at, to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as updated_at from webhook_events where provider_event_id = ?",
        [$eventId],
    );
}

/**
 * A recording logger in the place every newly built service resolves its logger from, so a case
 * can count the lines a transaction's failure writes: the runner's line is the count.
 */
function runnerLog(): TestHandler
{
    $handler = new TestHandler;
    app()->instance(LoggerInterface::class, new Logger('runner', [$handler]));

    return $handler;
}

/**
 * The staff-days a booking of the luna demo tenant touches (LunaFixture::tenant(), its schema and
 * its zone; no other tenant is read), each day in that zone from its block's start to its block's
 * end, held on a connection of their own in a transaction left open for the caller to roll back,
 * so a write that locks them waits out the house's lock_timeout and answers busy.
 */
function bookingStaffDaysHeld(string $probe, string $uuid): Connection
{
    $tenant = LunaFixture::tenant();
    $schema = $tenant->schema_name;
    $zone = $tenant->zone()->getName();
    $connection = LunaFixture::probe($probe);
    $connection->beginTransaction();
    $held = $connection->select(
        'select d.rev from '.$schema.'.staff_days d where exists (select 1 from '.$schema.'.booking_items i join '.$schema.'.bookings b on b.id = i.booking_id'
        .' where b.uuid = ?::uuid and i.staff_id = d.staff_id and d.day between (i.block_start_utc at time zone ?)::date and (i.block_end_utc at time zone ?)::date)'
        .' order by d.staff_id, d.day for update',
        [$uuid, $zone, $zone],
    );

    expect($held)->not->toBe([]);

    return $connection;
}

/**
 * The payment fake the test environment registers beside Stripe, PAYMENT_PROVIDER_BESIDE in
 * phpunit.xml: the one adapter the application's registry holds, so what it was asked is read
 * here whoever asked.
 */
function paymentFake(): FakePaymentAdapter
{
    app(ProviderRegistry::class);

    return app(FakePaymentAdapter::class);
}

/**
 * A tenant's payment connection store, as the application builds it.
 */
function paymentConnections(string $slug = 'luna'): PaymentConnections
{
    return app(PaymentFactory::class)->connections(Tenant::query()->where('slug', $slug)->sole());
}

/**
 * The credentials a test connects the fake with, one value for each field its descriptor
 * declares, each distinctive enough to look for wherever it must not be.
 *
 * @return array<string, string>
 */
function paymentFakeCredentials(): array
{
    return ['merchant_code' => 'MERCHANT-LUNA-0042', 'api_token' => 'pdk_token_9f3c1e77a2b4', 'signing_phrase' => 'correct horse paydesk staple'];
}

/**
 * A tenant connected to the fake provider in own mode, written through the store as the
 * Payments section will write it, in the state given and with the stored check given, null for
 * a connection nobody has checked.
 */
function connectPaymentFake(ConnectionState $state = ConnectionState::Active, ?bool $checkOk = null, string $slug = 'luna'): void
{
    $store = paymentConnections($slug);
    $store->write(new NewConnection(FakePaymentAdapter::SLUG, ConnectionMode::Own, $state, paymentFakeCredentials(), null, 'hook-token-'.$slug));

    if ($checkOk !== null) {
        $store->recordCheck($checkOk, app(Clock::class)->now());
    }
}

/*
 * My bookings: luna's bookings under an address written as rows, and the link rows and the link a
 * message carries read back. Shared by the link's tests, the session's and the browser walk,
 * which writes its bookings the same way.
 */

/** The address the My bookings tests book under and ask a link for. */
const MYB_CASEY = 'casey@example.test';

/**
 * A booking of luna's under an address, written as rows: a haircut with Elena at the UTC instant
 * given, in the status given, priced as luna prices it. Answers its uuid.
 */
function mybBooking(string $email, string $startUtc, string $status = 'confirmed', string $service = 'Haircut', string $staff = 'Elena Papadaki'): string
{
    $row = [...bookingRow(), 'customer_email' => $email, 'customer_name' => 'Casey Customer', 'status' => $status, 'total_minor' => 3500, 'source' => 'hosted'];
    $id = DB::table('tenant_luna.bookings')->insertGetId($row);
    $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));

    DB::table('tenant_luna.booking_items')->insert([
        ...itemRow($id, seamId('staff', $staff), $start->format('Y-m-d H:i:sP'), $start->add(new DateInterval('PT30M'))->format('Y-m-d H:i:sP')),
        'service_id' => seamId('services', $service),
        'price_minor' => 3500,
        'blocks' => $status === 'confirmed',
    ]);

    assert(is_string($row['uuid']));

    return $row['uuid'];
}

function mybTenant(): Tenant
{
    return Tenant::query()->where('slug', 'luna')->sole();
}

/**
 * The link rows luna holds, oldest first, as the columns read back.
 *
 * @return list<array<mixed>>
 */
function mybLinks(): array
{
    return array_values(array_map(fn (object $row): array => get_object_vars($row), DB::table('tenant_luna.customer_links')
        ->orderBy('id')
        ->selectRaw("email::text as email, token_hash, to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as created_at, to_char(expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as expires_at, used_at")
        ->get()
        ->all()));
}

/**
 * The token a mailed link carries, read out of the message the way a customer reads it.
 */
function mybTokenIn(string $body): string
{
    preg_match('#/my-bookings/([0-9a-f]{64})\b#', $body, $found);

    expect($found)->toHaveKey(1);

    return $found[1] ?? '';
}

/**
 * My bookings' second JSON script, the one the list mode reads beside the config: the session's
 * bookings or null, the flashed sentence, and the form's cross-site token.
 *
 * @return array<mixed>
 */
function mybInlined(DOMDocument $document): array
{
    $script = $document->getElementById(BookingPage::LIST_ID);

    expect($script)->toBeInstanceOf(DOMElement::class)
        ->and($script?->tagName)->toBe('script')
        ->and($script?->getAttribute('type'))->toBe('application/json');

    $inlined = json_decode((string) $script?->textContent, true, flags: JSON_THROW_ON_ERROR);

    assert(is_array($inlined));

    return $inlined;
}

/**
 * The link page's sentence above its button in a language, English unless named: chosen by the
 * session's length in days and that length spelled in words by ICU, as the page reads it.
 */
function mybOpenSentence(SiteLanguage $language = SiteLanguage::English): string
{
    $days = (new DateInterval(CustomerAccess::SESSION_LIFETIME))->d;

    return pageChoice(MyBookingsController::OPEN_SENTENCE, $days, ['count' => (string) (new NumberFormatter($language->value, NumberFormatter::SPELLOUT))->format($days)], $language);
}

/**
 * An operator of the platform's console, written as console:add-operator writes one, the
 * model's hashed cast taking the password. Named by its address so a test reads who it made.
 */
function addOperator(string $email = 'pat@inbox.example', string $password = 'operator password', string $name = 'Pat Operator'): Operator
{
    return Operator::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
}

/**
 * What the console's door writes into a session it opens: the two instants, both at the bound
 * clock's now, and the fingerprint of the operator's password, for a test that acts as an
 * operator on the console guard without passing the door, so the operator check admits it as a
 * sign-in this instant. The operator is the one given, or else the one the console guard already
 * acts as, actingAs having run first in a chain; with neither, the session carries no fingerprint
 * and the check refuses it as run out.
 *
 * @return array<string, string>
 */
function freshConsoleSession(?Operator $operator = null): array
{
    $now = SqlText::instant(app(Clock::class)->now());
    $acting = Auth::guard(ConsoleSession::GUARD)->user();
    $operator ??= $acting instanceof Operator ? $acting : null;
    $session = app(ConsoleSession::class);

    return [ConsoleSession::SIGNED_IN_AT => $now, ConsoleSession::LAST_SEEN_AT => $now, ...($operator === null ? [] : [ConsoleSession::PASSWORD => $session->fingerprint($operator)])];
}

/**
 * Makes the admin's web guard the default again after a test acted as an operator: acting as one
 * makes the console's guard the default for the rest of the application, which a test's requests
 * share, where the tenant host's session is another host's and never inherits it.
 */
function restoreWebGuard(): void
{
    Auth::shouldUse('web');
}

/**
 * Forgets every guard's signed-in person, as the next request's process would: a guard keeps the
 * person it read for the life of the application, which a test's requests share, so a row changed
 * between two requests is read afresh only once the guards are forgotten.
 */
function freshGuards(): void
{
    Auth::forgetGuards();
}

/**
 * Forgets the controller the named route resolved, as the next request's process would: a route
 * keeps the controller it first resolved, and the clock bound then with it, for the rest of the
 * test.
 */
function freshRouteController(string $name): void
{
    $route = Route::getRoutes()->getByName($name) ?? throw new LogicException(sprintf('No route is named %s.', $name));
    $route->flushController();
}

/**
 * Forgets the controller every route resolved, freshRouteController()'s forgetting over the whole
 * table: for a test whose clock moves under routes it has visited and does not name one by one.
 */
function freshRouteControllers(): void
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }
}
