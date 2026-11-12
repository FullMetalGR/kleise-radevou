<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * The confirmation thread: a customer books, confirms, is sent their confirmation, opens the
 * link in it and lands on their own booking.
 *
 * The done screen renders no link, so here the link comes from the message the confirmation
 * sent, which is the customer's own path end to end: nothing in this file knows the token, and
 * the only copy on this machine is the one inside the message.
 *
 * The messages are read from the test process because the application is served from it: the
 * browser plugin runs the kernel here, so the array transport phpunit.xml names is the same
 * object on both sides of the request.
 *
 * It runs on both demo tenants. pier's services sit on its implicit staff member, so its
 * summary names nobody, and its clock is Europe/London rather than Europe/Athens; the point of
 * the second run is that neither of those changes the thread.
 *
 * The bundle is the one npm run build wrote, which is why the build gate precedes this one.
 */

function threadTransport(): ArrayTransport
{
    $transport = app('mailer')->getSymfonyTransport();

    assert($transport instanceof ArrayTransport);

    return $transport;
}

/**
 * The one message the run sent to an address, which for the customer is their confirmation.
 */
function threadMessageTo(string $address): Email
{
    $found = [];

    foreach (threadTransport()->messages() as $message) {
        assert($message instanceof SentMessage);

        $original = $message->getOriginalMessage();

        assert($original instanceof Email);

        if (array_map(fn ($to): string => $to->getAddress(), $original->getTo()) === [$address]) {
            $found[] = $original;
        }
    }

    expect($found)->toHaveCount(1);

    return $found[0];
}

/**
 * The magic link a message carries, taken out of the body the way a customer takes it out of
 * their mail: by reading the address and opening it.
 */
function threadLink(Email $message): string
{
    $found = [];
    preg_match('#https?://\S+/manage/[0-9a-f-]+\?token=[0-9a-f]+#i', (string) $message->getTextBody(), $found);

    if ($found === []) {
        throw new RuntimeException('The confirmation carries no manage link for the customer to open.');
    }

    return $found[0];
}

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    threadTransport()->flush();

    // A confirmed booking schedules a reminder as well, which under the sync queue driver this
    // suite runs on goes out inside the confirm and would be a second message to the customer
    // here. It is switched off at both tenants rather than filtered out of the helper: a lead
    // of zero is how a tenant sends no reminders, and the reminder has its own file,
    // ReminderSeamTest.
    foreach (DemoTenantsSeeder::TENANTS as $demo) {
        settingsOf($demo['slug'])->update(['reminder_lead_hours' => 0]);
    }
});

afterEach(fn () => dropDemoSchemas());

it('books and confirms on luna, then reaches the booking through the link the confirmation carried', function () {
    $monday = Flow::day('Europe/Athens', 1);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'));

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 11)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        // The done screen renders no link and never the token: the message is where the
        // customer gets theirs.
        ->assertScript(FlowScripts::LOOKS_LIKE_A_TOKEN, false)
        ->assertNoJavaScriptErrors();

    $confirmation = threadMessageTo('casey@example.com');
    $attachments = $confirmation->getAttachments();

    expect((string) $confirmation->getTextBody())->toContain('Haircut')
        ->and(array_map(fn (DataPart $part): ?string => $part->getFilename(), $attachments))->toBe(['booking.ics']);

    // The business heard about it too, and what they were sent carries no link for anybody to
    // follow into a customer's booking.
    expect((string) threadMessageTo('owner@luna.example')->getTextBody())->not->toContain('/manage/');

    $this->visit(threadLink($confirmation))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        // The day as well as the hour: a view showing the right time on the wrong date is a
        // customer arriving a week late, and the hour alone cannot tell the two apart.
        ->assertScript(FlowScripts::summaryWhen($monday, '11:00'), true)
        ->assertSeeIn('.kr-booking__summary-staff', 'Elena Papadaki')
        ->assertSeeIn('.kr-booking__customer', 'casey@example.com')
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule'))
        ->assertNoJavaScriptErrors();
});

it('does the same on pier, whose clock is another one and whose summary names nobody', function () {
    $monday = Flow::day('Europe/London', 1);

    $page = $this->visit(hostedPage('pier'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Initial assessment')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 11)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Sam Swimmer')
        ->type('customer_email', 'sam@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertNoJavaScriptErrors();

    $confirmation = threadMessageTo('sam@example.com');
    $body = (string) $confirmation->getTextBody();

    expect($body)->toContain('Initial assessment')
        ->and($body)->toContain('Europe/London')
        ->and($body)->toContain('65.00 GBP')
        // Nobody is named as the person the appointment is with: pier's services run on its
        // implicit staff row, which stands for the business itself.
        ->and($body)->not->toContain(decisionLine('decision.facts.item', ['service' => 'Initial assessment', 'staff' => 'Pier Physiotherapy']));

    $this->visit(threadLink($confirmation))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::VIEW, 'ready')
        ->assertSeeIn('.kr-booking__summary-service', 'Initial assessment')
        ->assertScript(FlowScripts::summaryWhen($monday, '11:00'), true)
        ->assertScript(FlowScripts::SUMMARY_STAFF_LINES, 0)
        ->assertScript(FlowScripts::BUTTONS, Flow::buttons('manage.cancel', 'move.reschedule'))
        ->assertNoJavaScriptErrors();
});
