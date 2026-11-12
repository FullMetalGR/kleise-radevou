import { describe, expect, it } from 'vitest';
import { bookingSources, stripComments, type Source } from '../bookingSources';

/*
 * The audit that keeps 7.1 and 7.2 true as the app grows, over the source files of the booking
 * app as the stylesheet audit reads the built stylesheet. time.test.ts and money.test.ts pin what
 * the two modules do; this pins that nothing else does it. Outside time.ts no Date is built from
 * a value, so no wire value is ever handed to the bare constructor, whose reading of the
 * space-separated form is implementation-defined, and nothing calls Date.parse. Outside money.ts
 * nothing divides by a hundred or multiplies by a hundredth, which renders 5000 JPY as fifty yen:
 * the minor-unit scale is the currency's, derived in one place.
 *
 * Comments are stripped first, since prose may name what the code must not do; the tree comes
 * stripped from the one reader the three audits share, and a fixture is stripped here. The audit
 * is tested on fixtures, so a tree that broke a rule could not pass by breaking the audit too.
 */

/** A Date built from a value, or a string parsed into one: the clock's business, and time.ts's alone. */
const DATE_FROM_A_VALUE = /\bnew\s+Date\s*\(\s*[^)\s]|\bDate\s*\.\s*parse\s*\(/;

/** A hardcoded minor-unit scale: a division by a hundred or a multiplication by a hundredth. */
const HARDCODED_HUNDRED = /\/\s*(?:100|1e2)(?![\d.])|\*\s*0?\.01(?!\d)/;

function audit(sources: readonly Source[]): string[] {
    const findings: string[] = [];

    for (const { file, text } of sources) {
        const code = stripComments(text);

        if (file !== 'time.ts' && DATE_FROM_A_VALUE.test(code)) {
            findings.push(`${file}: a Date built from a value outside time.ts`);
        }

        if (file !== 'money.ts' && HARDCODED_HUNDRED.test(code)) {
            findings.push(`${file}: a hardcoded hundred outside money.ts`);
        }
    }

    return findings;
}

describe('the source audit of 7.1 and 7.2', () => {
    it('catches a Date built from a wire value, and a parse, outside time.ts, and allows both inside it', () => {
        expect(audit([{ file: 'Summary.tsx', text: 'const at = new Date(item.start);' }])).toEqual(['Summary.tsx: a Date built from a value outside time.ts']);
        expect(audit([{ file: 'hold.ts', text: 'const at = new Date( booking.holdExpiresAt );' }])).toHaveLength(1);
        expect(audit([{ file: 'hold.ts', text: 'const ms = Date.parse(wire);' }])).toHaveLength(1);
        expect(audit([{ file: 'time.ts', text: 'const at = new Date(utc);' }])).toEqual([]);
    });

    it('catches a division by a hundred or a hundredth outside money.ts, and allows it inside', () => {
        expect(audit([{ file: 'Summary.tsx', text: 'const major = row.priceMinor / 100;' }])).toEqual(['Summary.tsx: a hardcoded hundred outside money.ts']);
        expect(audit([{ file: 'Summary.tsx', text: 'const major = row.priceMinor/1e2;' }])).toHaveLength(1);
        expect(audit([{ file: 'Summary.tsx', text: 'const major = row.priceMinor * 0.01;' }])).toHaveLength(1);
        expect(audit([{ file: 'money.ts', text: 'const major = minor / 100;' }])).toEqual([]);
    });

    it('lets pass what is not either: the current instant, a thousand, a percentage of a width, a comment', () => {
        expect(audit([{ file: 'Flow.tsx', text: 'const now = Date.now(); const at = new Date(); const s = ms / 1000; const w = x / 1005;' }])).toEqual([]);
        expect(audit([{ file: 'Flow.tsx', text: '// never new Date(wire), never price / 100\n/* nor new Date(x) / 100 */\nconst url = "https://a/b";' }])).toEqual([]);
    });

    it('reads a tree that holds the booking app', () => {
        expect(bookingSources().map((source) => source.file)).toEqual(expect.arrayContaining(['time.ts', 'money.ts', 'Flow.tsx', 'ManageView.tsx']));
    });

    it('passes on the tree as it stands', () => {
        expect(audit(bookingSources())).toEqual([]);
    });
});

/*
 * No client code names its own source: the server stamps a booking hosted, embed or admin
 * from the request's origin, so nothing in the booking app may spell one of the three as a
 * value or carry a source key on the wire.
 */

/** One of the source column's three values as a string literal, or a source key in a body. */
const A_SOURCE = /(['"`])(?:hosted|embed|admin)\1|\bsource\s*:\s*['"`]|['"`]source['"`]\s*:/;

function namesASource(sources: readonly Source[]): string[] {
    return sources.filter(({ text }) => A_SOURCE.test(stripComments(text))).map(({ file }) => `${file}: names a booking source`);
}

describe('the source audit of 0.1', () => {
    it('catches a source value or a source key, and lets pass the config source the boot reads and prose', () => {
        expect(namesASource([{ file: 'hold.ts', text: "const body = { ...rest, source: 'hosted' };" }])).toEqual(['hold.ts: names a booking source']);
        expect(namesASource([{ file: 'hold.ts', text: 'const kind = "embed";' }])).toHaveLength(1);
        expect(namesASource([{ file: 'hold.ts', text: "const body = { 'source': where };" }])).toHaveLength(1);
        expect(namesASource([{ file: 'App.tsx', text: 'render(App, { source: deps.source }); // the embed is stamped embed' }])).toEqual([]);
    });

    it('passes on the tree as it stands', () => {
        expect(namesASource(bookingSources())).toEqual([]);
    });
});
