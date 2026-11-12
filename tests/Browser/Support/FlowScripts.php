<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use DateTimeImmutable;

/**
 * The scripts the booking flow's browser tests evaluate in the page, in one place so that
 * every file reads the same DOM the same way. A Pest file's own constants are global once
 * the file is loaded, so two files spelling the same name would collide in one process, and
 * what that produces is one script copied under two names until the markup moves under one
 * of them.
 */
final class FlowScripts
{
    /**
     * Resolves true once every frame on the page is ready and nothing in it is still busy,
     * so the assertions that follow read a settled catalog, or a settled grid on the when
     * step, which is busy while its query is in flight; false after five seconds.
     */
    public const string SETTLED = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const frames = Array.from(document.querySelectorAll('.kr-booking__frame'));
                const settled = frames.length > 0 && frames.every((frame) =>
                    frame.getAttribute('data-kr-state') === 'ready' && frame.querySelector('[aria-busy="true"]') === null,
                );
                if (settled) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Resolves true once the week row holds the given number of days, which is the
     * availability answer having landed and rendered; false after five seconds.
     */
    public const string DAYS_RENDERED = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                if (document.querySelectorAll('.kr-booking__day').length === %d) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Resolves true once the week row holds the given day's button and nothing in the frame is
     * busy, which is the answer for that day's week having landed and rendered; false after five
     * seconds. Every week holds seven days, so a count cannot tell one week from the next.
     */
    public const string DAY_RENDERED = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const frame = document.querySelector('.kr-booking__frame');
                if (frame !== null && frame.querySelector('.kr-booking__day[value="%s"]') !== null && frame.querySelector('[aria-busy="true"]') === null) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Resolves true once the flow container carries the given step, which is the answer to
     * whatever the customer last pressed having landed; false after five seconds.
     */
    public const string STEP_REACHED = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const frame = document.querySelector('.kr-booking__frame');
                if (frame !== null && frame.getAttribute('data-kr-step') === '%s') {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Resolves true once a polite region on the page holds the given text, which a region
     * mounted empty takes one effect after it appears; false after five seconds.
     */
    public const string SAID_POLITELY = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const said = Array.from(document.querySelectorAll('[role="status"]')).map((region) => region.textContent).join(' ');
                if (said.includes('%s')) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Whether anything on the page reads like the manage token: sixty-four hexadecimal
     * characters. It is returned once and lives in memory, and must reach no attribute, no
     * text node and no URL.
     */
    public const string LOOKS_LIKE_A_TOKEN = "/[0-9a-f]{64}/.test(document.documentElement.outerHTML) || performance.getEntriesByType('resource').some((entry) => /[0-9a-f]{64}/.test(entry.name))";

    /**
     * The URLs the page requested that carry something reading like the manage token, as their
     * paths in the order they were requested. The two allowed to are the recovery's re-read of a
     * refused confirm and the release, a GET and a DELETE, which carry it as a query parameter
     * because neither method has a body.
     */
    public const string TOKEN_URLS = "JSON.stringify(performance.getEntriesByType('resource').map((entry) => entry.name).filter((name) => /[0-9a-f]{64}/.test(name)).map((name) => new URL(name).pathname))";

    /**
     * Whether any attribute or text node on the page reads like the manage token.
     */
    public const string TOKEN_IN_MARKUP = '/[0-9a-f]{64}/.test(document.documentElement.outerHTML)';

    public const string SERVICE_NAMES = "Array.from(document.querySelectorAll('.kr-booking__service-name')).map((name) => name.textContent).join('|')";

    public const string STEP = "document.querySelector('.kr-booking__frame').getAttribute('data-kr-step')";

    /**
     * Whether the summary card's one Continue is disabled: before the first service, on the time
     * step, and while a hold flies.
     */
    public const string CONTINUE_DISABLED = "document.querySelector('.kr-booking__continue').disabled";

    public const string CHOICES = "Array.from(document.querySelectorAll('.kr-booking__choice')).map((choice) => choice.textContent + ':' + choice.getAttribute('aria-pressed')).join('|')";

    public const string FOCUSED_FRAME = "document.activeElement === document.querySelector('.kr-booking__frame')";

    public const string DAY_VALUES = "Array.from(document.querySelectorAll('.kr-booking__day')).map((day) => day.value).join('|')";

    public const string PRESSED_DAYS = "Array.from(document.querySelectorAll('.kr-booking__day')).filter((day) => day.getAttribute('aria-pressed') === 'true').map((day) => day.value).join('|')";

    public const string SLOT_VALUES = "Array.from(document.querySelectorAll('.kr-booking__slot')).map((slot) => slot.value).join('|')";

    public const string SLOT_LABELS = "Array.from(document.querySelectorAll('.kr-booking__slot')).map((slot) => slot.textContent).join('|')";

    /**
     * The period control's buttons, each its name and count as read aloud with whether it is the
     * one chosen; empty for a day whose starts sit in one period, which shows no control.
     */
    public const string PERIODS = "Array.from(document.querySelectorAll('.kr-booking__period')).map((period) => period.textContent + ':' + period.getAttribute('aria-pressed')).join('|')";

    /**
     * Whether nothing on the page is an alert. A worded refusal and everything after it must
     * have none anywhere.
     */
    public const string NO_ALERT = "document.querySelectorAll('[role=\"alert\"]').length === 0";

    /**
     * Whether the review step's action button is disabled, which is what the expiry flag holds.
     */
    public const string CONFIRM_DISABLED = "document.querySelector('.kr-booking__confirm').disabled";

    /**
     * Whether the review step's countdown reads a live hold: a whole minute or more left, as a
     * hold fresh from the server has, in the minutes-and-seconds reading.
     */
    public const string COUNTDOWN_FRESH = "(() => { const value = document.querySelector('.kr-booking__countdown-value'); return value !== null && /^[1-9][0-9]*:[0-5][0-9]$/.test(value.textContent); })()";

    /**
     * The review step's action button's label, which is the payment branch: "Confirm booking"
     * without a checkout_url and "Continue to payment" with one.
     */
    public const string ACTION_LABEL = "document.querySelector('.kr-booking__confirm').textContent";

    /**
     * Wraps the page's fetch so every answer to a POST on the holds path is kept as the text it
     * arrived as, which is the wire and not what the app parsed out of it. The bundle calls the
     * page's fetch at request time, so a wrapper installed before the details submit sees the
     * hold. Answers true once installed.
     */
    public const string RECORD_HOLD_ANSWERS = <<<'JS'
        (() => {
            const original = window.fetch;
            window.krHoldAnswers = [];
            window.fetch = (input, init) => original(input, init).then((response) => {
                if (new URL(String(input)).pathname === '/api/v1/holds' && init !== undefined && init.method === 'POST') {
                    response.clone().text().then((text) => window.krHoldAnswers.push(text));
                }
                return response;
            });
            return true;
        })()
        JS;

    /**
     * The hold answers RECORD_HOLD_ANSWERS kept, as one JSON array of their texts; resolves once
     * there is at least one, or with an empty array after five seconds.
     */
    public const string HOLD_ANSWERS = <<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                const kept = window.krHoldAnswers ?? [];
                if (kept.length > 0 || Date.now() - started > 5000) {
                    resolve(JSON.stringify(kept));
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;

    /**
     * Which of the manage view's three panels the frame is showing.
     */
    public const string VIEW = "document.querySelector('.kr-booking__frame').getAttribute('data-kr-view')";

    /**
     * Every control the frame offers, in order, which is how a test says that a booking can be
     * cancelled or that there is nothing left to press.
     */
    public const string BUTTONS = "Array.from(document.querySelectorAll('.kr-booking__frame button')).map((button) => button.textContent).join('|')";

    /**
     * Whether the manage view's reschedule dialog is on screen.
     */
    public const string DIALOG_OPEN = "document.querySelector('.kr-booking__frame [role=\"dialog\"]') !== null";

    /**
     * Whether the focus sits on the reschedule dialog's own container, where 6.5 lands it on open.
     */
    public const string FOCUSED_DIALOG = "document.activeElement !== null && document.activeElement === document.querySelector('.kr-booking__frame [role=\"dialog\"]')";

    /**
     * Whether the focus sits on the manage view's Reschedule button, enabled, where 6.5 returns it
     * when the dialog closes by any route.
     */
    public const string FOCUSED_TRIGGER = "(() => { const trigger = document.querySelector('.kr-booking__frame .kr-booking__reschedule'); return trigger !== null && document.activeElement === trigger && !trigger.disabled; })()";

    /**
     * How many items of a summary name a person. Nought is what a tenant whose services run on
     * its implicit staff row looks like: that row stands for the business itself.
     */
    public const string SUMMARY_STAFF_LINES = "document.querySelectorAll('.kr-booking__summary-staff').length";

    /**
     * The days-rendered script for a row of the given length.
     */
    public static function daysRendered(int $count): string
    {
        return sprintf(self::DAYS_RENDERED, $count);
    }

    /**
     * The day-rendered script for one tenant-local day.
     */
    public static function dayRendered(DateTimeImmutable $day): string
    {
        return sprintf(self::DAY_RENDERED, $day->format('Y-m-d'));
    }

    /**
     * The step-reached script for one step.
     */
    public static function stepReached(string $step): string
    {
        return sprintf(self::STEP_REACHED, $step);
    }

    /**
     * The said-politely script for one sentence.
     */
    public static function saidPolitely(string $text): string
    {
        return sprintf(self::SAID_POLITELY, $text);
    }

    /**
     * Whether the first summary line reads as the given tenant-local day and hour.
     *
     * The date is compared against one the page's own Intl renders, because the label is in the
     * browser's locale and a PHP-side spelling of it would be this machine's guess at that
     * locale rather than an assertion about the booking. What is being asked is which day and
     * which hour the view is showing, and a view showing another day answers false whatever
     * locale it is in.
     */
    public static function summaryWhen(DateTimeImmutable $day, string $time): string
    {
        return sprintf(
            <<<'JS'
                (() => {
                    const shown = document.querySelector('.kr-booking__summary-when').textContent;
                    const day = new Intl.DateTimeFormat(undefined, { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' })
                        .format(new Date(Date.UTC(%d, %d, %d)));

                    return shown.includes(day) && shown.includes('%s');
                })()
                JS,
            (int) $day->format('Y'),
            (int) $day->format('n') - 1,
            (int) $day->format('j'),
            $time,
        );
    }
}
