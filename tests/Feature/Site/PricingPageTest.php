<?php

use App\Billing\CatalogueUnreadable;
use App\Site\PricingSheet;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Tenancy\TenantUrls;
use Dom\HTMLDocument;
use Psr\Log\LoggerInterface;
use Tests\Support\SeamLog;
use Tests\TestCase;

/*
 * The pricing page, as served and read with no script run: two cards in the plan catalogue's
 * order, each the plan's name and its monthly amount from the catalogue's display money,
 * formatted in the page's language with the line saying it is a month's price ex VAT beside it,
 * what the plan includes, the seven days free with no card and the signup action; a table of
 * what each plan has, its rows drawn from the plans' features; and the six objections of
 * pricing.php answered. The names are config/billing.php's, Solo and Team, and a changed
 * catalogue changes the page with no edit to it. A catalogue that cannot be sold from, a price
 * identifier unset as in development, answers the page at 200 with the cards and no amount and
 * logs once, never a 500.
 *
 * The page's head and its h1 are CentralSurfaceTest's walk, which lists the page with no edit to
 * it. A Greek sentence is read through the translator with the locale named, never spelled here;
 * an amount's euro sign and the no-break space before it in Greek are escapes.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * The pricing page in a language as served.
 */
function pricingDocument(TestCase $test, SiteLanguage $language): HTMLDocument
{
    $response = $test->get(app(TenantUrls::class)->central($language->uri('/pricing')));

    $response->assertOk()->assertViewIs('site.pricing');

    return siteDocument($response);
}

/**
 * The text of each cell of a table row's, the row's head first.
 *
 * @return list<list<string>>
 */
function pricingRows(HTMLDocument $document, string $selector): array
{
    $rows = [];

    foreach ($document->querySelectorAll($selector) as $row) {
        $cells = [];

        foreach ($row->querySelectorAll('th, td') as $cell) {
            $cells[] = trim((string) $cell->textContent);
        }

        $rows[] = $cells;
    }

    return $rows;
}

/**
 * Binds a logger that keeps what the page tells it.
 */
function pricingLog(): SeamLog
{
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);

    return $log;
}

it('names the plans Solo and Team in config/billing.php, the one home', function () {
    expect(config('billing.plans.floor.name'))->toBe('Solo')
        ->and(config('billing.plans.staff.name'))->toBe('Team');
});

it('shows two cards in the catalogue\'s order, each its name, its monthly amount ex VAT in the page\'s language, what it includes, the trial and the signup action', function (SiteLanguage $language, array $amounts) {
    $document = pricingDocument($this, $language);
    $floor = siteSentences('pricing.includes', $language);

    expect(siteTexts($document, 'main h1'))->toBe([siteSentence('pricing.heading', $language)])
        ->and(siteTexts($document, 'main .site-pricing-lead'))->toBe([siteSentence('pricing.lead', $language, siteTrialDays())])
        ->and($document->querySelectorAll('main .site-pricing-card'))->toHaveCount(2)
        ->and(siteTexts($document, 'main .site-pricing-card h2'))->toBe(['Solo', 'Team'])
        ->and(siteTexts($document, 'main .site-pricing-card .site-pricing-amount'))->toBe($amounts)
        ->and(siteTexts($document, 'main .site-pricing-card .site-pricing-period'))->toBe(array_fill(0, 2, siteSentence('pricing.card.period', $language)))
        ->and(siteTexts($document, 'main .site-pricing-card .site-pricing-trial'))->toBe(array_fill(0, 2, siteSentence('pricing.card.trial', $language, siteTrialDays())))
        ->and(siteTexts($document, 'main .site-pricing-card#floor .site-pricing-includes li'))->toBe([...$floor, siteSentence('pricing.features.staff.without', $language)])
        ->and(siteTexts($document, 'main .site-pricing-card#staff .site-pricing-includes li'))->toBe([...$floor, siteSentence('pricing.features.staff.with', $language)])
        ->and(siteTexts($document, 'main .site-pricing-card a.site-pricing-signup'))->toBe(array_fill(0, 2, siteSentence('pricing.card.action', $language)));

    foreach ($document->querySelectorAll('main .site-pricing-card a.site-pricing-signup') as $action) {
        expect($action->getAttribute('href'))->toBe(app(SitePages::class)->signup($language))->toBe(app(TenantUrls::class)->central($language->uri('/signup')));
    }
})->with([
    'English, a decimal point after the euro sign' => [SiteLanguage::English, ["\u{20AC}14.95", "\u{20AC}24.95"]],
    'Greek, a decimal comma before the euro sign' => [SiteLanguage::Greek, ["14,95\u{A0}\u{20AC}", "24,95\u{A0}\u{20AC}"]],
]);

it('says ex VAT beside every amount, the Greek page with the Greek VAT term from its own file', function () {
    $english = pricingDocument($this, SiteLanguage::English);
    $greek = pricingDocument($this, SiteLanguage::Greek);

    expect(__('pricing.card.period', [], 'en'))->toBe('a month, ex VAT')
        ->and(__('pricing.card.period', [], 'el'))->toBeString()->not->toBe(__('pricing.card.period', [], 'en'))->not->toBe('pricing.card.period')
        ->and(siteTexts($english, 'main .site-pricing-period'))->toBe(array_fill(0, 2, __('pricing.card.period', [], 'en')))
        ->and(siteTexts($greek, 'main .site-pricing-period'))->toBe(array_fill(0, 2, __('pricing.card.period', [], 'el')));

    foreach ([$english, $greek] as $document) {
        foreach ($document->querySelectorAll('main .site-pricing-amount') as $amount) {
            expect($amount->nextElementSibling?->getAttribute('class'))->toContain('site-pricing-period');
        }
    }
});

it('follows a changed catalogue with no edit to the page: amounts swapped and a plan renamed', function (SiteLanguage $language, array $amounts) {
    config([
        'billing.plans.floor.display.amount' => 2495,
        'billing.plans.staff.display.amount' => 1990,
        'billing.plans.staff.name' => 'Studio',
    ]);

    $document = pricingDocument($this, $language);

    expect(siteTexts($document, 'main .site-pricing-card h2'))->toBe(['Solo', 'Studio'])
        ->and(siteTexts($document, 'main .site-pricing-card .site-pricing-amount'))->toBe($amounts)
        ->and(pricingRows($document, 'main .site-pricing-table thead tr'))->toBe([[siteSentence('pricing.table.item', $language), 'Solo', 'Studio']]);
})->with([
    'English' => [SiteLanguage::English, ["\u{20AC}24.95", "\u{20AC}19.90"]],
    'Greek' => [SiteLanguage::Greek, ["24,95\u{A0}\u{20AC}", "19,90\u{A0}\u{20AC}"]],
]);

it('formats an amount in its own currency and that currency\'s scale', function () {
    config(['billing.plans.floor.display' => ['amount' => 5000, 'currency' => 'JPY']]);

    expect(siteTexts(pricingDocument($this, SiteLanguage::English), 'main .site-pricing-card#floor .site-pricing-amount'))->toBe(["\u{A5}5,000"]);
});

it('draws the table\'s rows from the plans\' features: what every plan has, then Team adding staff members', function (SiteLanguage $language) {
    $document = pricingDocument($this, $language);
    $included = siteSentence('pricing.table.included', $language);

    expect(siteTexts($document, 'main .site-pricing-table h2'))->toBe([siteSentence('pricing.table.heading', $language)])
        ->and(pricingRows($document, 'main .site-pricing-table thead tr'))->toBe([[siteSentence('pricing.table.item', $language), 'Solo', 'Team']])
        ->and(pricingRows($document, 'main .site-pricing-table tbody tr'))->toBe([
            ...array_map(fn (string $line): array => [$line, $included, $included], siteSentences('pricing.includes', $language)),
            [siteSentence('pricing.features.staff.label', $language), siteSentence('pricing.features.staff.lacks', $language), siteSentence('pricing.features.staff.has', $language)],
        ]);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('carries no staff row and no staff line where no plan grants staff, the rows being the features\' and no list of the view\'s', function () {
    config(['billing.plans.staff.features' => []]);

    $document = pricingDocument($this, SiteLanguage::English);
    $floor = siteSentences('pricing.includes', SiteLanguage::English);

    expect(array_column(pricingRows($document, 'main .site-pricing-table tbody tr'), 0))->toBe($floor)
        ->and(siteTexts($document, 'main .site-pricing-card#staff .site-pricing-includes li'))->toBe($floor);
});

it('answers the six objections of pricing.php, each a heading over its answer, in the file\'s order', function (SiteLanguage $language) {
    $document = pricingDocument($this, $language);
    $items = siteCopyKeys('pricing.faq.items', $language);

    expect($items)->toBe(['commission', 'contract', 'card', 'invoice', 'trial', 'change'])
        ->and(siteTexts($document, 'main .site-pricing-faq > h2'))->toBe([siteSentence('pricing.faq.heading', $language)])
        ->and(siteTexts($document, 'main .site-pricing-faq-item > h3'))->toBe(array_map(fn (string $item): string => siteSentence('pricing.faq.items.'.$item.'.question', $language), $items));

    foreach ($items as $item) {
        expect(siteTexts($document, 'main .site-pricing-faq-item#'.$item.' > p'))->toBe(siteSentences('pricing.faq.items.'.$item.'.answer', $language, siteTrialDays()), $item);
    }
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('agrees with the home, the features and the FAQ pages: the commission claim narrowed as the hero carries it, in the same sentence wherever the site makes it, and the trial\'s end', function (SiteLanguage $language) {
    $claim = siteSentence('home.hero.claims.commission', $language);
    $pricing = siteSentences('pricing.faq.items.commission.answer', $language)[0] ?? '';
    $faq = siteSentences('faq.items.commission.answer', $language)[0] ?? '';

    expect($claim)->not->toBe('')
        ->and(substr($pricing, 0, strlen($claim)))->toBe($claim)
        ->and(substr($faq, 0, strlen($claim)))->toBe($claim)
        ->and(siteSentence('home.gets.items.payments.body', $language))->toContain($claim)
        ->and(implode(' ', siteSentences('features.groups.payments.body', $language)))->toContain($claim)
        ->and(siteSentences('pricing.faq.items.trial.answer', $language))->toBe(siteSentences('faq.items.ends.answer', $language));
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('answers 200 with the cards and no amount where a price identifier is unset as in development, logging the failure once', function (SiteLanguage $language) {
    config(['billing.plans.staff.price' => null]);
    $log = pricingLog();

    $document = pricingDocument($this, $language);

    expect(siteTexts($document, 'main .site-pricing-card h2'))->toBe(['Solo', 'Team'])
        ->and($document->querySelectorAll('main .site-pricing-amount, main .site-pricing-period'))->toHaveCount(0)
        ->and(siteTexts($document, 'main .site-pricing-card .site-pricing-trial'))->toBe(array_fill(0, 2, siteSentence('pricing.card.trial', $language, siteTrialDays())))
        ->and(siteTexts($document, 'main .site-pricing-card a.site-pricing-signup'))->toBe(array_fill(0, 2, siteSentence('pricing.card.action', $language)))
        ->and(pricingRows($document, 'main .site-pricing-table thead tr'))->toBe([[siteSentence('pricing.table.item', $language), 'Solo', 'Team']])
        ->and(array_map(fn (array $record): array => [$record['level'], $record['message'], $record['context']], $log->records))
        ->toBe([['error', PricingSheet::CATALOGUE_LOGGED, ['message' => CatalogueUnreadable::noPrice('staff')->getMessage()]]]);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('answers 200 with the page and its FAQ, no card and no table, where no plan can be named at all, logging once', function () {
    config(['billing.plans' => null]);
    $log = pricingLog();

    $document = pricingDocument($this, SiteLanguage::English);

    expect($document->querySelectorAll('main .site-pricing-card, main .site-pricing-table'))->toHaveCount(0)
        ->and(siteTexts($document, 'main h1'))->toBe([siteSentence('pricing.heading', SiteLanguage::English)])
        ->and($document->querySelectorAll('main .site-pricing-faq-item'))->toHaveCount(6)
        ->and(array_map(fn (array $record): array => [$record['level'], $record['message']], $log->records))->toBe([['error', PricingSheet::CATALOGUE_LOGGED]]);
});

it('carries no form, no script and no control that hides an answer', function (SiteLanguage $language) {
    $document = pricingDocument($this, $language);

    expect($document->querySelectorAll('form'))->toHaveCount(0)
        ->and($document->querySelectorAll('script'))->toHaveCount(0)
        ->and($document->querySelectorAll('main details, main [hidden], main button'))->toHaveCount(0);
})->with([
    'English' => [SiteLanguage::English],
    'Greek' => [SiteLanguage::Greek],
]);

it('declares the formatter\'s intl extension in composer.json and the lock\'s platform block, so a host without it is refused at composer install and never answers 500', function () {
    $json = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    expect(data_get($json, 'require.ext-intl'))->toBe('*')
        ->and(data_get($lock, 'platform.ext-intl'))->toBe('*');
});
