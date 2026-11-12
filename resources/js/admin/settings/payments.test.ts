import { describe, expect, it } from 'vitest';
import { fill } from '../../booking/placeholders';
import { formatAmount, formatPercent, type TenantClock } from '@/lib/format';
import { KEYED_SHELL, keyedPayments } from '@/testing/keyed';
import { checkedSentence, type Cost, costOf, CREDENTIAL_MAX, type CredentialField, credentialProblems, credentialsBody, feeInForceOf, GONE_SENTENCE, informationSentence, PAYMENT_ERROR, paymentFailureSentence, reasonOf, standingSentence, stateSentence, storedSecretLabels } from './payments';
import type { PaymentsKey } from './words';

/*
 * The Payments section's words and checks, over invented providers: no file under resources/js
 * names a registered one. The words are the keyed payments section, so each expectation reads
 * its sentence through the same table and a filled sentence shows what was put into it.
 */

const CLOCK: TenantClock = { timezone: 'Europe/Athens', dateFormat: 'd/m/Y', timeFormat: 'H:i' };

const payments = keyedPayments();

const FIELDS: CredentialField[] = [
    { name: 'merchant', label: 'merchant-label', help: 'merchant-help', secret: false },
    { name: 'token', label: 'token-label', help: 'token-help', secret: true },
    { name: 'phrase', label: 'phrase-label', help: 'phrase-help', secret: true },
];

describe('the cost of a mode', () => {
    const RATE = payments.text('cost.rate', { provider: 'Tillpoint' });
    const sentence = (cost: Cost) => fill(cost.line, { rate: cost.rate });
    const whole = (key: PaymentsKey, fee?: string) => payments.text(key, { rate: RATE, ...(fee === undefined ? {} : { fee }) });
    const platform = (percent: string | null, fixed: number | null, currency = 'EUR') => costOf({ mode: 'platform', offered: true, takes_fee: true, fee: { percent, fixed_minor: fixed, currency } }, 'Tillpoint', payments);

    it("says both modes' cost in one rule, the provider named from the prop and its own processing rate the part the section links", () => {
        const own = costOf({ mode: 'own', offered: true, takes_fee: false, fee: null }, 'Tillpoint', payments);

        expect(own.rate).toBe(RATE);
        expect(sentence(own)).toBe(whole('cost.own'));
        expect(own.line).toContain(':rate');
        expect(platform('0', null).rate).toBe(RATE);
        expect(sentence(platform('0', null))).toBe(whole('cost.nothing'));
        expect(costOf({ mode: 'own', offered: true, takes_fee: false, fee: null }, 'Cardhouse', payments).rate).toBe(payments.text('cost.rate', { provider: 'Cardhouse' }));
    });

    it('reads the platform taking nothing wherever the configured fee comes to nothing for the tenant', () => {
        for (const cost of [platform('0', null), platform(null, null), platform('0', 0), platform('0.00', 0)]) {
            expect(sentence(cost)).toBe(whole('cost.nothing'));
        }
    });

    it("states a fee that is not zero in each of its three shapes, the percentage in the user's language and the fixed part in the tenant's currency at its own scale", () => {
        const percent = (figure: number) => formatPercent(figure, payments.locale, false, 2);

        expect(sentence(platform('1.5', null))).toBe(whole('cost.figure', percent(1.5)));
        expect(sentence(platform('1.25', null))).toBe(whole('cost.figure', percent(1.25)));
        expect(percent(1.25)).toContain('1.25');
        expect(sentence(platform(null, 25))).toBe(whole('cost.figure', formatAmount(25, 'EUR', payments.locale)));
        expect(sentence(platform('2.5', 25))).toBe(whole('cost.figure', payments.text('cost.fee', { percent: percent(2.5), fixed: formatAmount(25, 'EUR', payments.locale) })));
        expect(platform('2.5', 25).rate).toBe(RATE);
        expect(formatAmount(25, 'EUR')).toContain('0.25');
        expect(sentence(platform(null, 30, 'JPY'))).toContain(formatAmount(30, 'JPY', payments.locale));
        expect(formatAmount(30, 'JPY')).toContain('30');
        expect(formatAmount(30, 'JPY')).not.toContain('0.3');
        expect(sentence(platform(null, 25, 'USD'))).toBe(whole('cost.figure', formatAmount(25, 'USD', payments.locale)));
    });

    it("spells the percentage in Greek for a Greek user, the decimal comma the language's", () => {
        const greek = keyedPayments('el');
        const cost = costOf({ mode: 'platform', offered: true, takes_fee: true, fee: { percent: '1.5', fixed_minor: null, currency: 'EUR' } }, 'Tillpoint', greek);

        expect(sentence(cost)).toContain(formatPercent(1.5, greek.locale, false, 2));
        expect(formatPercent(1.5, greek.locale, false, 2)).toContain('1,5');
    });

    it("spells a fee's fixed part in the user's language beside its percentage, the currency the tenant's", () => {
        const greek = keyedPayments('el');
        const cost = costOf({ mode: 'platform', offered: true, takes_fee: true, fee: { percent: '2.5', fixed_minor: 25, currency: 'EUR' } }, 'Tillpoint', greek);
        const fixed = formatAmount(25, 'EUR', greek.locale);

        expect(fill(cost.line, { rate: cost.rate })).toBe(greek.text('cost.figure', { rate: cost.rate, fee: greek.text('cost.fee', { percent: formatPercent(2.5, greek.locale, false, 2), fixed }) }));
        expect(fixed).toContain('0,25');
        expect(fixed).not.toBe(formatAmount(25, 'EUR'));
    });

    it('states no figure, neither nothing nor a share, where the prop carries no fee, as on a platform mode not offered', () => {
        const cost = costOf({ mode: 'platform', offered: false, takes_fee: true, fee: null }, 'Tillpoint', payments);

        expect(sentence(cost)).toBe(whole('cost.unstated'));
    });
});

describe('the fee in force on a connection made through us', () => {
    const RATE = payments.text('cost.rate', { provider: 'Tillpoint' });
    const sentence = (cost: Cost) => fill(cost.line, { rate: cost.rate });
    const inForce = (percent: string | null, fixed: number | null, currency = 'EUR') => feeInForceOf({ percent, fixed_minor: fixed, currency }, 'Tillpoint', payments);
    const percent = (figure: number) => formatPercent(figure, payments.locale, false, 2);

    it('states the platform taking nothing at launch and wherever the fee comes to nothing for the tenant, the provider\'s own processing rate the linked part', () => {
        expect(inForce('0', null).rate).toBe(RATE);

        for (const cost of [inForce('0', null), inForce(null, null), inForce('0', 0), inForce('0.00', null)]) {
            expect(sentence(cost)).toBe(payments.text('cost.in_force', { rate: RATE }));
        }
    });

    it('states a fee raised after connecting with its figure, the fixed part in the tenant\'s currency at its own scale', () => {
        expect(sentence(inForce('1.5', null))).toBe(payments.text('cost.in_force_figure', { rate: RATE, fee: percent(1.5) }));
        expect(sentence(inForce('2.5', 25))).toBe(payments.text('cost.in_force_figure', { rate: RATE, fee: payments.text('cost.fee', { percent: percent(2.5), fixed: formatAmount(25, 'EUR', payments.locale) }) }));
        expect(sentence(inForce(null, 30, 'JPY'))).toBe(payments.text('cost.in_force_figure', { rate: RATE, fee: formatAmount(30, 'JPY', payments.locale) }));
    });
});

describe('the state', () => {
    it('gives each of the four states its own sentence, naming the provider where the fix is theirs, and an unknown state verbatim', () => {
        for (const state of ['active', 'restricted', 'disabled', 'pending'] as const) {
            expect(stateSentence({ name: 'Tillpoint', state }, payments)).toBe(payments.text(`states.${state}`, { provider: 'Tillpoint' }));
        }

        expect(stateSentence({ name: 'Tillpoint', state: 'mystery' as never }, payments)).toBe('mystery');
    });

    it('says in one line that a provider taking payments still needs information, naming it from the prop', () => {
        expect(informationSentence('Tillpoint', payments)).toBe(payments.text('information', { provider: 'Tillpoint' }));
    });

    it('reads the provider\'s reason verbatim, any other shape as its JSON, and nothing for nothing', () => {
        expect(reasonOf({ reason: 'Invalid API Key provided: [secret]' })).toBe('Invalid API Key provided: [secret]');
        expect(reasonOf({ due: ['external_account'] })).toBe('{"due":["external_account"]}');
        expect(reasonOf(['individual.verification.document'])).toBe('["individual.verification.document"]');
        expect(reasonOf(null)).toBeNull();
        expect(reasonOf([])).toBeNull();
        expect(reasonOf({})).toBeNull();
    });

    it('spells the last test on the tenant\'s clock, one whole sentence per outcome, and says when none has run', () => {
        const at = '2026-11-02T06:00:00+00:00';

        expect(checkedSentence({ last_checked_at: at, last_check_ok: true }, CLOCK, payments)).toBe(payments.text('checked.passed', { when: '02/11/2026 08:00' }));
        expect(checkedSentence({ last_checked_at: at, last_check_ok: false }, CLOCK, payments)).toBe(payments.text('checked.failed', { when: '02/11/2026 08:00' }));
        expect(checkedSentence({ last_checked_at: at, last_check_ok: null }, CLOCK, payments)).toBe(payments.text('checked.unknown', { when: '02/11/2026 08:00' }));
        expect(checkedSentence({ last_checked_at: null, last_check_ok: null }, CLOCK, payments)).toBe(payments.text('checked.never'));
        expect(checkedSentence({ last_checked_at: 'yesterday', last_check_ok: true }, CLOCK, payments)).toBe(payments.text('checked.passed', { when: 'yesterday' }));
    });

    it('spells where a connection made through us stands with its provider, one whole sentence per standing, and says when nothing was heard', () => {
        const row = (last_check_ok: boolean | null, last_checked_at: string | null = '2026-11-02T06:00:00+00:00') => standingSentence({ name: 'Tillpoint', last_checked_at, last_check_ok }, CLOCK, payments);
        const values = { provider: 'Tillpoint', when: '02/11/2026 08:00' };

        expect(row(true)).toBe(payments.text('standing.taking', values));
        expect(row(false)).toBe(payments.text('standing.outstanding', values));
        expect(row(null)).toBe(payments.text('standing.unfinished', values));
        expect(row(true, null)).toBe(payments.text('standing.never', { provider: 'Tillpoint' }));
    });
});

describe('the credential form', () => {
    it('requires every declared field, blank after trimming included, and bounds each at the server\'s length, in the server\'s words', () => {
        const missing = (field: string) => payments.text('validation.missing', { field });

        expect(credentialProblems(FIELDS, {}, payments)).toEqual({ merchant: missing('merchant-label'), token: missing('token-label'), phrase: missing('phrase-label') });
        expect(credentialProblems(FIELDS, { merchant: 'M-1', token: '   ', phrase: 'x'.repeat(CREDENTIAL_MAX + 1) }, payments)).toEqual({ token: missing('token-label'), phrase: payments.text('validation.too_long', { field: 'phrase-label' }) });
        expect(credentialProblems(FIELDS, { merchant: 'M-1', token: 't', phrase: 'x'.repeat(CREDENTIAL_MAX) }, payments)).toEqual({});
    });

    it('sends the provider and every declared field, nothing undeclared', () => {
        const provider = { slug: 'tillpoint', name: 'Tillpoint', rate_url: 'https://tillpoint.example/pricing', modes: [], fields: FIELDS };
        expect(credentialsBody(provider, { merchant: 'M-1', token: 't', phrase: 'p', stray: 'no' })).toEqual({ provider: 'tillpoint', credentials: { merchant: 'M-1', token: 't', phrase: 'p' } });
    });

    it('labels the stored secrets in the descriptor\'s order, a non-secret field never among them, and an undeclared name by its name', () => {
        const provider = { slug: 'tillpoint', name: 'Tillpoint', rate_url: 'https://tillpoint.example/pricing', modes: [], fields: FIELDS };
        expect(storedSecretLabels(provider, ['phrase', 'token'])).toEqual(['token-label', 'phrase-label']);
        expect(storedSecretLabels(provider, ['merchant'])).toEqual([]);
        expect(storedSecretLabels(undefined, ['token'])).toEqual(['token']);
    });
});

describe('a refusal', () => {
    it('is the envelope\'s sentence, the framework\'s 404 worded here, and the fallback where no answer came', () => {
        expect(paymentFailureSentence({ status: 409, code: 'connection_contended', sentence: 'contended-token', segment: null, crossings: [] }, payments)).toBe('contended-token');
        expect(paymentFailureSentence({ status: 404, code: null, sentence: KEYED_SHELL.toaster.fallback, segment: null, crossings: [] }, payments)).toBe(payments.text(GONE_SENTENCE));
        expect(paymentFailureSentence({ status: null, code: null, sentence: null, segment: null, crossings: [] }, payments)).toBe(payments.text(PAYMENT_ERROR));
    });

    it('is the envelope\'s sentence for the live calls\' limit and for a resume the platform cannot serve, neither worded as a connection gone', () => {
        const limited = 'limited-token';
        const unavailable = payments.text('refusals.unavailable');

        expect(paymentFailureSentence({ status: 429, code: 'rate_limited', sentence: limited, segment: null, crossings: [] }, payments)).toBe(limited);
        expect(paymentFailureSentence({ status: 409, code: 'platform_unavailable', sentence: unavailable, segment: null, crossings: [] }, payments)).toBe(unavailable);
    });
});
