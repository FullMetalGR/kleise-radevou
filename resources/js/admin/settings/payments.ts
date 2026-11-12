import { formatAmount, formatInstant, formatPercent, type TenantClock } from '@/lib/format';
import type { Failure } from '@/lib/visits';
import type { PaymentsKey, PaymentsWords } from './words';

/*
 * The Payments section's data and words. The prop is the server's, built from the provider
 * registry and the tenant's stored row alone, so the section is generated from the registry's
 * declarations: nothing here or in the components names a provider, and a provider added to the
 * registry renders with no change on this side. The words branch on the mode, the state and
 * whether a mode takes the fee, never on which provider it is; a provider's name and the address
 * of its published rate arrive in the prop.
 *
 * The words are keys of the payments section, each constant holding its key under its old name,
 * every sentence whole: a cost, a state, a last test and a standing are one key per case, filled
 * with the provider's name, the instant or the fee's figure; the cost leaves its :rate unfilled
 * for the section to place its link there through placed().
 *
 * The deal is stated beside both options before either is chosen, in one rule: the platform takes
 * nothing, and the provider's own processing rate applies, linked to the rate the provider
 * publishes. An offered platform mode carries the fee as configured; where it is not nothing its
 * figure takes the place of "nothing": a percentage, a fixed amount in the tenant's own currency
 * at that currency's scale, or both. A connection made through us states the fee in force the same
 * way, so a fee raised after connecting is seen rather than discovered.
 */

export type PaymentModeName = 'platform' | 'own';

/**
 * The platform's fee as configured, on an offered platform mode and on a connection made through
 * us: its percentage as a decimal string, and its fixed part in minor units of the tenant's own
 * currency, null where the configuration names none for it.
 */
export type PaymentFee = { percent: string | null; fixed_minor: number | null; currency: string };

export type PaymentMode = { mode: PaymentModeName; offered: boolean; takes_fee: boolean; fee: PaymentFee | null };

export type CredentialField = { name: string; label: string; help: string; secret: boolean };

/** A registered provider as the prop declares it; rate_url is the https address of its own published processing rate. */
export type PaymentProvider = { slug: string; name: string; rate_url: string; modes: PaymentMode[]; fields: CredentialField[] };

export type ConnectionState = 'pending' | 'active' | 'restricted' | 'disabled';

export type PaymentConnection = {
    /** The registry's slug, matched against the providers and never spelled here. */
    provider: string;
    name: string;
    mode: PaymentModeName;
    state: ConnectionState;
    /** A UTC instant with its offset, converted to the tenant's clock on the way to the screen. */
    last_checked_at: string | null;
    last_check_ok: boolean | null;
    /**
     * What the provider last said, verbatim; own mode's failed check is { reason }, a sentence of
     * ours already worded by the server in the request's language, the provider's words inside
     * it as sent, or the stored reason as written where the row holds no key (another adapter's,
     * or one written before the key existed).
     */
    requirements: Record<string, unknown> | unknown[] | null;
    /** A connection made through us taking payments while its provider still lists information it needs. */
    information_due: boolean;
    /** The fee in force on a connection made through us, null on an own-mode one. */
    fee: PaymentFee | null;
    /** The names of the secret fields holding a value, never a value. */
    stored_secrets: string[];
    webhook_url: string | null;
};

export type Payments = { providers: PaymentProvider[]; connection: PaymentConnection | null };

/** The section's eight writes, each its own button and its own busy state. */
export type PaymentAction = 'connect' | 'platform' | 'resume' | 'test' | 'rotate' | 'disable' | 'enable' | 'disconnect';

/** The wait of each write, a key of the payments section. */
export const PAYMENT_WAIT: Record<PaymentAction, PaymentsKey> = {
    connect: 'waits.connect',
    platform: 'waits.platform',
    resume: 'waits.resume',
    test: 'waits.test',
    rotate: 'waits.rotate',
    disable: 'waits.disable',
    enable: 'waits.enable',
    disconnect: 'waits.disconnect',
};

export const DEGRADE_SENTENCE = 'degrade' satisfies PaymentsKey;

export const CASH_SENTENCE = 'cash' satisfies PaymentsKey;

export const NOT_OFFERED = 'not_offered' satisfies PaymentsKey;

export const MASK = '********';

/** A write meeting no connection to act on answers the framework's 404, worded here. */
export const GONE_SENTENCE = 'gone' satisfies PaymentsKey;

export const PAYMENT_ERROR = 'error' satisfies PaymentsKey;

/** How each mode connects, in the tenant's words. */
export const MODE_NAMES: Record<PaymentModeName, PaymentsKey> = {
    platform: 'modes.platform',
    own: 'modes.own',
};

/**
 * A cost sentence whole, its :rate left unfilled for the section to place the provider's own
 * processing rate there as a link (lib/strings placed()), and the rate's own words.
 */
export type Cost = { line: string; rate: string };

/**
 * The cost sentences, one whole key per case: own mode costs nothing; platform mode's platform
 * takes nothing, or the figure configured where it comes to something; and the provider's own
 * processing rate applies to both. A platform mode the prop carries no fee for, one not offered,
 * states no figure, neither nothing nor a share, since none is known. A connection made through us
 * states the fee in force without the offer's opening words.
 */
const COST_SENTENCES = {
    own: 'cost.own',
    nothing: 'cost.nothing',
    figure: 'cost.figure',
    unstated: 'cost.unstated',
    inForce: 'cost.in_force',
    inForceFigure: 'cost.in_force_figure',
} satisfies Record<string, PaymentsKey>;

/**
 * What a mode costs, as the prop states it, its :rate left for the provider's own processing
 * rate, which the section links. A platform fee's figure is a percentage in the user's language,
 * an amount in the tenant's currency at its own scale and in the user's language, or the two
 * joined by one key; a figure coming to nothing for the tenant's currency reads as nothing.
 */
export function costOf(mode: PaymentMode, name: string, words: PaymentsWords): Cost {
    return costLine(mode.takes_fee ? platformCost(mode.fee, words) : { key: COST_SENTENCES.own }, name, words);
}

/**
 * The fee in force on a connection made through us, as the prop states it, its :rate left for the
 * provider's own processing rate as an offered mode's is: at launch nothing, and the figure of a
 * fee raised after connecting.
 */
export function feeInForceOf(fee: PaymentFee, name: string, words: PaymentsWords): Cost {
    const figure = figureOf(fee, words);

    return costLine(figure === null ? { key: COST_SENTENCES.inForce } : { key: COST_SENTENCES.inForceFigure, fee: figure }, name, words);
}

/** A cost sentence's key and the figure it states, where it states one. */
type CostLine = { key: PaymentsKey; fee?: string };

function costLine(line: CostLine, name: string, words: PaymentsWords): Cost {
    return { line: words.text(line.key, line.fee === undefined ? {} : { fee: line.fee }), rate: words.text('cost.rate', { provider: name }) };
}

function platformCost(fee: PaymentFee | null, words: PaymentsWords): CostLine {
    if (fee === null) {
        return { key: COST_SENTENCES.unstated };
    }

    const figure = figureOf(fee, words);

    return figure === null ? { key: COST_SENTENCES.nothing } : { key: COST_SENTENCES.figure, fee: figure };
}

/** The decimals a fee's percentage is configured to, at most: basis points. */
const FEE_DECIMALS = 2;

/**
 * A fee's figure, or null where it comes to nothing for the tenant's currency: its percentage in
 * the user's language and its fixed part in the tenant's money, joined by one key where it has
 * both.
 */
function figureOf(fee: PaymentFee, words: PaymentsWords): string | null {
    const percent = fee.percent !== null && Number(fee.percent) > 0 ? formatPercent(Number(fee.percent), words.locale, false, FEE_DECIMALS) : null;
    const fixed = fee.fixed_minor !== null && fee.fixed_minor > 0 ? formatAmount(fee.fixed_minor, fee.currency, words.locale) : null;

    if (percent !== null && fixed !== null) {
        return words.text('cost.fee', { percent, fixed });
    }

    return percent ?? fixed;
}

/** The connection's mode as a line under the provider's name. */
export function modeLine(mode: PaymentModeName, words: PaymentsWords): string {
    return words.text(mode === 'own' ? 'connected.own' : 'connected.platform');
}

/** Each state's own sentence, by key. */
const STATE_SENTENCES: Record<ConnectionState, PaymentsKey> = {
    pending: 'states.pending',
    active: 'states.active',
    restricted: 'states.restricted',
    disabled: 'states.disabled',
};

/**
 * The state's own sentence, read from the stored row and never from the provider: the fix
 * differs in every state. A state this build does not know renders verbatim.
 */
export function stateSentence(connection: Pick<PaymentConnection, 'name' | 'state'>, words: PaymentsWords): string {
    const key: PaymentsKey | undefined = STATE_SENTENCES[connection.state];

    return key === undefined ? connection.state : words.text(key, { provider: connection.name });
}

/**
 * The one line under an active row made through us whose provider still lists information it
 * needs, in place of quoting the list.
 */
export function informationSentence(name: string, words: PaymentsWords): string {
    return words.text('information', { provider: name });
}

/**
 * What the provider said: own mode's reason as the server worded it, any other shape verbatim as
 * its JSON; nothing for nothing.
 */
export function reasonOf(requirements: PaymentConnection['requirements']): string | null {
    if (requirements === null || (Array.isArray(requirements) ? requirements.length === 0 : Object.keys(requirements).length === 0)) {
        return null;
    }

    const reason = Array.isArray(requirements) ? undefined : requirements.reason;

    return typeof reason === 'string' && reason.trim() !== '' ? reason : JSON.stringify(requirements);
}

/**
 * When the connection was last tested and how it went, on the tenant's clock: one whole
 * sentence per outcome, passed, failed or none recorded, and one where no test has run.
 */
export function checkedSentence(connection: Pick<PaymentConnection, 'last_checked_at' | 'last_check_ok'>, clock: TenantClock, words: PaymentsWords): string {
    if (connection.last_checked_at === null) {
        return words.text('checked.never');
    }

    const key: PaymentsKey = connection.last_check_ok === null ? 'checked.unknown' : connection.last_check_ok ? 'checked.passed' : 'checked.failed';

    return words.text(key, { when: formatInstant(connection.last_checked_at, clock) });
}

/**
 * Where a connection made through us stands with its provider, on the tenant's clock: the line
 * under a platform row in place of a test's, since the tenant never tests one and the provider's
 * own callback moves it. The stored check reads true where the provider takes payments, false
 * where it wants something and null where the onboarding is unfinished; one whole sentence each,
 * and one where nothing has been heard.
 */
export function standingSentence(connection: Pick<PaymentConnection, 'name' | 'last_checked_at' | 'last_check_ok'>, clock: TenantClock, words: PaymentsWords): string {
    if (connection.last_checked_at === null) {
        return words.text('standing.never', { provider: connection.name });
    }

    const key: PaymentsKey = connection.last_check_ok === null ? 'standing.unfinished' : connection.last_check_ok ? 'standing.taking' : 'standing.outstanding';

    return words.text(key, { provider: connection.name, when: formatInstant(connection.last_checked_at, clock) });
}

/** The longest credential the server admits, PaymentCredentialsRequest's bound. */
export const CREDENTIAL_MAX = 1000;

/**
 * The credential form's own checks, the server's rules in the server's words, its validation keys
 * read from the section: every declared field is required, a save being the whole set, and none
 * is longer than the bound. The answer is keyed by field name and holds the failing fields alone.
 */
export function credentialProblems(fields: readonly CredentialField[], values: Readonly<Record<string, string>>, words: PaymentsWords): Record<string, string> {
    const problems: Record<string, string> = {};

    for (const field of fields) {
        const typed = (values[field.name] ?? '').trim();

        if (typed === '') {
            problems[field.name] = words.text('validation.missing', { field: field.label });
        } else if (typed.length > CREDENTIAL_MAX) {
            problems[field.name] = words.text('validation.too_long', { field: field.label });
        }
    }

    return problems;
}

/** The body of a save: the provider and every declared field as typed, nothing undeclared. */
export function credentialsBody(provider: PaymentProvider, values: Readonly<Record<string, string>>): { provider: string; credentials: Record<string, string> } {
    return { provider: provider.slug, credentials: Object.fromEntries(provider.fields.map((field) => [field.name, values[field.name] ?? ''])) };
}

/** The labels of the secret fields a connection holds a value for, in the descriptor's order; a name the descriptor does not declare by its name. */
export function storedSecretLabels(provider: PaymentProvider | undefined, stored: readonly string[]): string[] {
    const declared = provider?.fields ?? [];
    const known = declared.filter((field) => field.secret && stored.includes(field.name)).map((field) => field.label);
    const unknown = stored.filter((name) => !declared.some((field) => field.name === name));

    return [...known, ...unknown];
}

/** A refusal in one sentence: the envelope's detail, a 404 worded here, or the fallback for no answer at all. */
export function paymentFailureSentence(failure: Failure, words: PaymentsWords): string {
    return failure.status === 404 ? words.text(GONE_SENTENCE) : (failure.sentence ?? words.text(PAYMENT_ERROR));
}
