import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Notice } from './api';
import type { Booking } from './booking';
import { ENGLISH_WORDS, keyed, said } from '../bookingWords';
import { BOOKING_LINES_EN } from './lines.generated';
import type { Words } from './words';
import { CONFIRM_LABEL, IMPORTANT_INFO_HEADING, PAYMENT_LABEL, RESTART_LABEL, ReviewStep } from './ReviewStep';
import { BOOKING_HEADING, TOTAL_LABEL, WITH_STAFF, ZONE_SENTENCE } from './Summary';

/*
 * Step review: the summary read entirely off the returned booking and never off the app's own
 * selections, its times in the tenant's clock, its money in the booking's own currency, the
 * countdown above it all when the answer carried an expiry, the action button whose label is the
 * branch, the owner's Important info above it where the tenant set one, the notice region under
 * it, and "Pick another time" last when the flow offers it. Rendered with the keyed table, and
 * once with the English file.
 */

const TIMEZONE = 'Pacific/Auckland';

// 22:30 UTC on the 6th is 10:30 on the 7th in Auckland; 23:15 UTC is 11:15 and 22:45 is 10:45.
const BOOKING: Booking = {
    uuid: '7b7f0e5a-1111-4000-8000-000000000001',
    status: 'pending',
    holdClass: 'checkout',
    holdExpiresAt: '2026-04-06T22:45:00+00:00',
    customerName: 'Casey Customer',
    customerEmail: 'casey@example.com',
    customerPhone: null,
    totalMinor: 5000,
    currency: 'JPY',
    items: [
        { sort: 0, serviceId: 1, serviceName: 'Haircut', staffId: 7, staffName: 'Elena', start: '2026-04-06T22:30:00+00:00', end: '2026-04-06T23:15:00+00:00', priceMinor: 3500 },
        { sort: 1, serviceId: 4, serviceName: 'Beard trim', staffId: 7, staffName: null, start: '2026-04-06T23:15:00+00:00', end: '2026-04-06T23:35:00+00:00', priceMinor: 1500 },
    ],
};

// Fifteen minutes before the hold's deadline, so the countdown reads 15:00.
const NOW = Date.UTC(2026, 3, 6, 22, 30);

const clock = (): number => NOW;

// The step's own notice region, which is not the countdown's milestone region above it.
const NOTICE = '.kr-booking__step > [role="status"]';

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
    confirmed.mockClear();
    paid.mockClear();
    expired.mockClear();
    restarted.mockClear();
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(async () => {
    await act(() => {
        root.unmount();
    });
    container.remove();
});

interface Props {
    readonly booking: Booking;
    readonly notice: Notice | null;
    readonly busy: boolean;
    readonly expired: boolean;
    readonly restartable: boolean;
    readonly importantInfo: string | null;
    readonly onConfirm: () => void;
    readonly onPay: () => void;
}

const confirmed = vi.fn();
const paid = vi.fn();
const expired = vi.fn();
const restarted = vi.fn();

async function render(overrides: Partial<Props> = {}, words?: Words): Promise<void> {
    const props: Props = { booking: BOOKING, notice: null, busy: false, expired: false, restartable: false, importantInfo: null, onConfirm: confirmed, onPay: paid, ...overrides };

    await act(() => {
        root.render(keyed(<ReviewStep {...props} timezone={TIMEZONE} now={clock} onExpire={expired} onRestart={restarted} />, words));
    });
}

function action(): HTMLButtonElement {
    const found = container.querySelector<HTMLButtonElement>('.kr-booking__confirm');

    if (found === null) {
        throw new Error('The review step rendered no action button.');
    }

    return found;
}

async function press(el: HTMLElement): Promise<void> {
    await act(() => {
        el.click();
    });
}

function texts(selector: string): string[] {
    return Array.from(container.querySelectorAll(selector)).map((node) => node.textContent ?? '');
}

describe('the summary', () => {
    it('names every returned item in chain order, with the staff member where the answer names one', async () => {
        await render();

        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
        expect(texts('.kr-booking__summary-staff')).toEqual([said(WITH_STAFF, { staff: 'Elena' })]);
    });

    it('renders every time in the tenant clock, whatever the machine is set to', async () => {
        await render();

        expect(texts('.kr-booking__summary-when')[0]).toContain('10:30');
        expect(texts('.kr-booking__summary-when')[1]).toContain('11:15');
        expect(container.querySelector('.kr-booking__timezone')?.textContent).toBe(said(ZONE_SENTENCE, { zone: TIMEZONE }));
    });

    it('heads the card with its key and words the total by its key', async () => {
        await render();

        expect(container.querySelector('.kr-booking__review > .kr-booking__subtitle')?.textContent).toBe(said(BOOKING_HEADING));
        expect(container.querySelector('.kr-booking__total span')?.textContent).toBe(said(TOTAL_LABEL));
    });

    it('scales the money by the booking currency, so 5000 JPY is five thousand yen and not fifty', async () => {
        await render();

        expect(container.querySelector('.kr-booking__total')?.textContent).toContain('5,000');
        expect(container.querySelector('.kr-booking__total')?.textContent).not.toContain('50.00');
        expect(texts('.kr-booking__summary-price')).toEqual(['3,500', '1,500'].map((amount) => expect.stringContaining(amount) as unknown as string));
    });

    it('reads the total off the booking and never off the items it was handed', async () => {
        await render({ booking: { ...BOOKING, totalMinor: 4200, currency: 'EUR' } });

        expect(container.querySelector('.kr-booking__total')?.textContent).toContain('42.00');
    });
});

describe('the countdown', () => {
    it('renders from the server deadline when the answer carried one', async () => {
        await render();

        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('15:00');
        expect(expired).not.toHaveBeenCalled();
    });

    it('renders none for a null expiry, which never blocks the confirm', async () => {
        await render({ booking: { ...BOOKING, holdExpiresAt: null } });

        expect(container.querySelector('.kr-booking__countdown')).toBeNull();
        expect(action().disabled).toBe(false);
        expect(expired).not.toHaveBeenCalled();
    });

    it('carries no sentence restating the deadline, and keeps the time zone line', async () => {
        await render();

        expect(container.textContent).not.toContain('holding your time');
        expect(container.textContent).not.toContain('10:45');
        expect(container.querySelector('.kr-booking__timezone')?.textContent).toContain(TIMEZONE);
    });

    it('tells the flow when a deadline already past is reached', async () => {
        await render({ booking: { ...BOOKING, holdExpiresAt: '2026-04-06T22:29:00+00:00' } });

        expect(container.querySelector('.kr-booking__countdown-value')?.textContent).toBe('0:00');
        expect(expired).toHaveBeenCalledTimes(1);
    });
});

describe('the action button', () => {
    it('reads Confirm booking with no checkout_url and Continue to payment with one, and never renders both', async () => {
        await render();

        expect(action().textContent).toBe(said(CONFIRM_LABEL));
        expect(container.textContent).not.toContain(said(PAYMENT_LABEL));

        await render({ booking: { ...BOOKING, checkoutUrl: 'https://pay.example.com/session/abc' } });

        expect(action().textContent).toBe(said(PAYMENT_LABEL));
        expect(container.textContent).not.toContain(said(CONFIRM_LABEL));
        expect(container.querySelectorAll('.kr-booking__confirm')).toHaveLength(1);
    });

    it('reads the English file\'s labels on both branches', async () => {
        await render({}, ENGLISH_WORDS);

        expect(action().textContent).toBe(BOOKING_LINES_EN.review.confirm);
        expect(container.querySelector('.kr-booking__review > .kr-booking__subtitle')?.textContent).toBe(BOOKING_LINES_EN.booking.heading);
        expect(container.querySelector('.kr-booking__total span')?.textContent).toBe(BOOKING_LINES_EN.booking.total);
        expect(container.querySelector('.kr-booking__timezone')?.textContent).toBe(BOOKING_LINES_EN.zone.replace(':zone', TIMEZONE));
        expect(texts('.kr-booking__summary-staff')).toEqual([BOOKING_LINES_EN.summary.staff.replace(':staff', 'Elena')]);

        await render({ booking: { ...BOOKING, checkoutUrl: 'https://pay.example.com/session/abc' } }, ENGLISH_WORDS);

        expect(action().textContent).toBe(BOOKING_LINES_EN.review.pay);
    });

    it('asks the flow to confirm on the confirm branch, once per press', async () => {
        await render();

        await press(action());
        await press(action());

        expect(confirmed).toHaveBeenCalledTimes(2);
    });

    it('asks the flow to hand off on the payment branch and never to confirm, so the press is never a no-op', async () => {
        await render({ booking: { ...BOOKING, checkoutUrl: 'https://pay.example.com/session/abc' } });

        await press(action());

        expect(paid).toHaveBeenCalledTimes(1);
        expect(confirmed).not.toHaveBeenCalled();
    });

    it('never asks the flow to hand off on the confirm branch', async () => {
        await render();

        await press(action());

        expect(paid).not.toHaveBeenCalled();
    });

    it('never renders the checkout url, which is a credential-carrying link', async () => {
        await render({ booking: { ...BOOKING, checkoutUrl: 'https://pay.example.com/session/abc' } });

        expect(container.innerHTML).not.toContain('pay.example.com');
    });

    it('disables while a confirm is in flight and once the hold is known to have expired', async () => {
        await render();

        expect(action().disabled).toBe(false);

        await render({ busy: true });

        expect(action().disabled).toBe(true);

        await render({ expired: true });

        expect(action().disabled).toBe(true);
    });
});

describe('the notice region', () => {
    it('renders a refusal politely with the whole summary still on screen', async () => {
        await render({ notice: { text: 'This booking can no longer be confirmed.', tone: 'polite' } });

        // The region mounts empty and takes its text one effect later, which the act above ran.
        expect(container.querySelector(NOTICE)?.textContent).toBe('This booking can no longer be confirmed.');
        expect(container.querySelector('[role="alert"]')).toBeNull();
        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
        expect(container.querySelector('.kr-booking__total')?.textContent).toContain('5,000');
    });

    it('renders a transport failure as an alert, the summary still standing', async () => {
        await render({ notice: { text: 'Something went wrong.', tone: 'alert' } });

        expect(container.querySelector('[role="alert"]')?.textContent).toBe('Something went wrong.');
        expect(texts('.kr-booking__summary-service')).toEqual(['Haircut', 'Beard trim']);
    });

    it('exists before anything lands in it, and holds nothing while nothing is refused', async () => {
        await render();

        expect(container.querySelector(NOTICE)).not.toBeNull();
        expect(container.querySelector(NOTICE)?.textContent).toBe('');
    });
});

describe('the important info', () => {
    const INFO = 'Please arrive five minutes early.\nWe take cash only.';

    it('renders the tenant text under its heading, above the action button, where the tenant set one', async () => {
        await render({ importantInfo: INFO });

        const block = container.querySelector('.kr-booking__important');

        expect(block?.querySelector('h3')?.textContent).toBe(said(IMPORTANT_INFO_HEADING));
        expect(block?.querySelector('.kr-booking__important-text')?.textContent).toBe(INFO);
        expect(block?.compareDocumentPosition(action())).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('renders neither the heading nor a paragraph where the tenant set none', async () => {
        await render({ importantInfo: null });

        expect(container.querySelector('.kr-booking__important')).toBeNull();
        expect(container.textContent).not.toContain(said(IMPORTANT_INFO_HEADING));
    });

    it('keeps the tenant text as text, so markup in it renders as written and builds nothing', async () => {
        await render({ importantInfo: 'Bring <b>cash</b>' });

        expect(container.querySelector('.kr-booking__important-text')?.textContent).toBe('Bring <b>cash</b>');
        expect(container.querySelector('.kr-booking__important b')).toBeNull();
    });
});

describe('the order of the step', () => {
    it('is the countdown, the summary, the important info, the action button, the notice region, the restart control', async () => {
        await render({ notice: { text: 'This booking can no longer be confirmed.', tone: 'polite' }, restartable: true, importantInfo: 'Please arrive five minutes early.' });

        const flat = Array.from(
            container.querySelectorAll('.kr-booking__countdown, .kr-booking__summary, .kr-booking__important, .kr-booking__confirm, .kr-booking__step > .kr-booking__notice--polite, .kr-booking__restart'),
        ).map((node) => ['countdown', 'summary', 'important', 'confirm', 'notice', 'restart'].find((part) => node.className.includes(`kr-booking__${part}`)));

        expect(flat).toEqual(['countdown', 'summary', 'important', 'confirm', 'notice', 'restart']);
    });
});

describe('the restart control', () => {
    it('renders when the flow offers it and not otherwise', async () => {
        await render();

        expect(container.textContent).not.toContain(said(RESTART_LABEL));
        expect(container.querySelectorAll('button')).toHaveLength(1);

        await render({ restartable: true });

        expect(container.querySelector('.kr-booking__restart')?.textContent).toBe(said(RESTART_LABEL));
    });

    it('tells the flow it was taken, once per press, whatever the confirm is doing', async () => {
        await render({ restartable: true, busy: true, expired: true });

        await press(container.querySelector('.kr-booking__restart') as HTMLElement);

        expect(restarted).toHaveBeenCalledTimes(1);
        expect(confirmed).not.toHaveBeenCalled();
    });
});

describe('what the step never shows', () => {
    it('never renders the credential or the identifier the customer has no use for', async () => {
        await render();

        expect(container.innerHTML).not.toContain(BOOKING.uuid);
        expect(container.innerHTML).not.toContain('manage');
    });
});
