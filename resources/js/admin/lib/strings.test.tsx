import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, expectTypeOf, it } from 'vitest';
import { localeOf } from '../../booking/language';
import { formatCount } from './format';
import { ADMIN_LINES_EN } from '../lines.generated';
import { KEYED_SHELL, keyedShell, keyedTable, WithShell } from '../testing/keyed';
import type { BillingStrings, EmailsStrings, HomeStrings, PaymentsStrings, SettingsStrings, ShellStrings, StaffStrings } from '../types';
import { placed, type ShellKey, shellOf, stringsOf, useShell } from './strings';

/*
 * The admin's words on the client: a section answers a key with its line, else the key itself, its
 * placeholders filled by the one fill(), a choice chosen by the translator's syntax; the shell's
 * section reaches a component through the provider and reads the keys themselves outside one; the
 * keyed table the tests render with is built from lang/en/admin as the export writes it; and each
 * section's type in types.ts is held to its file's keys, so the two cannot part.
 */

/** A table's shape: every line a string, every group its own shape, nothing else. */
type Shape<T> = { -readonly [K in keyof T]: T[K] extends string ? string : Shape<T[K]> };

type ChoiceCase = { line: string; chosen: Record<string, string> };

describe('the shell section\'s type', () => {
    it('is the keys of lang/en/admin/shell.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.shell>>().toEqualTypeOf<ShellStrings>();
    });

    it('is the file the keyed table walks, group by group', () => {
        const groups: (keyof ShellStrings)[] = ['nav', 'login', 'set_password', 'top_bar', 'banners', 'boundary', 'not_found', 'toaster', 'copy', 'kit', 'shared', 'validation', 'refusals', 'flashes'];

        expect(Object.keys(ADMIN_LINES_EN.shell)).toEqual(groups);
        expect(Object.keys(KEYED_SHELL)).toEqual(groups);
    });
});

describe('the home section\'s type', () => {
    it('is the keys of lang/en/admin/home.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.home>>().toEqualTypeOf<HomeStrings>();
    });
});

describe('the staff section\'s type', () => {
    it('is the keys of lang/en/admin/staff.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.staff>>().toEqualTypeOf<StaffStrings>();
    });
});

describe('the settings section\'s type', () => {
    it('is the keys of lang/en/admin/settings.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.settings>>().toEqualTypeOf<SettingsStrings>();
    });
});

describe('the payments section\'s type', () => {
    it('is the keys of lang/en/admin/payments.php, no more and no fewer, our words for the providers\' fields typed by their shape', () => {
        expectTypeOf<Shape<Omit<typeof ADMIN_LINES_EN.payments, 'fields'>>>().toEqualTypeOf<Omit<PaymentsStrings, 'fields'>>();
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.payments.fields>>().toExtend<PaymentsStrings['fields']>();
    });
});

describe('the billing section\'s type', () => {
    it('is the keys of lang/en/admin/billing.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.billing>>().toEqualTypeOf<BillingStrings>();
    });
});

describe('the emails section\'s type', () => {
    it('is the keys of lang/en/admin/emails.php, no more and no fewer', () => {
        expectTypeOf<Shape<typeof ADMIN_LINES_EN.emails>>().toEqualTypeOf<EmailsStrings>();
    });
});

describe('stringsOf', () => {
    const table = { a: { one: 'One :name.', plain: 'Plain' }, n: '{1} one thing|[2,*] :count things' };

    it('answers a key read with dots, its placeholders filled', () => {
        const words = stringsOf(table, 'en');

        expect(words.text('a.one', { name: 'Ana' })).toBe('One Ana.');
        expect(words.text('a.plain')).toBe('Plain');
    });

    it('answers a key it has no line for, and every key outside a table, with the key itself', () => {
        expect(stringsOf(table, 'en').text('a' as 'a.plain')).toBe('a');
        expect(stringsOf<typeof table>(null, 'en').text('a.one', { name: 'Ana' })).toBe('a.one');
    });

    it('chooses a line by a count, :count filled with it', () => {
        expect(stringsOf(table, 'en').choice('n', 1)).toBe('one thing');
        expect(stringsOf(table, 'en').choice('n', 3)).toBe('3 things');
    });

    it('fills a chosen line\'s :count spelled by formatCount in its locale, grouped as each language groups it', () => {
        for (const language of ['en', 'el'] as const) {
            const words = stringsOf(table, language);

            expect(words.choice('n', 1234)).toBe(`${formatCount(1234, words.locale)} things`);
        }

        expect(formatCount(1234, localeOf('el'))).not.toBe(formatCount(1234, localeOf('en')));
    });

    it('keeps a caller\'s own count value over the formatted figure, so a line can keep its placeholder', () => {
        expect(stringsOf(table, 'en').choice('n', 1234, { count: ':count' })).toBe(':count things');
    });

    it('answers a line with no bounds whole at every count', () => {
        const plain = { line: ':count waiting' };

        expect(stringsOf(plain, 'en').choice('line', 1)).toBe(`${formatCount(1, localeOf('en'))} waiting`);
        expect(stringsOf(plain, 'en').choice('line', 2)).toBe(`${formatCount(2, localeOf('en'))} waiting`);
    });

    it('carries the language and the locale the formatters take for it on this machine', () => {
        expect(stringsOf(table, 'el').language).toBe('el');
        expect(stringsOf(table, 'el').locale).toBe(localeOf('el'));
        expect(stringsOf(table, 'en').locale).toBe(localeOf('en'));
    });
});

describe('the choice', () => {
    const cases = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Support/choices.json'), 'utf8')) as readonly ChoiceCase[];

    it.each(cases.flatMap((one) => Object.entries(one.chosen).map(([count, chosen]) => [one.line, Number(count), chosen] as const)))('chooses %j by %d through a section\'s words as the translator and the bundle do: %j', (line, count, chosen) => {
        expect(stringsOf({ line }, 'en').choice('line', count, { count: ':count' })).toBe(chosen);
    });
});

describe('the shell\'s provider', () => {
    function Probe({ word }: { word: ShellKey }) {
        const shell = useShell();

        return (
            <span data-language={shell.language} data-locale={shell.locale}>
                {shell.text(word)}
            </span>
        );
    }

    it('hands a component the section in the language given', () => {
        expect(
            renderToStaticMarkup(
                <WithShell language="el">
                    <Probe word="toaster.dismiss" />
                </WithShell>,
            ),
        ).toBe(`<span data-language="el" data-locale="${localeOf('el')}">${keyedShell().text('toaster.dismiss')}</span>`);
    });

    it('reads the key itself outside a provider, in English names', () => {
        expect(renderToStaticMarkup(<Probe word="toaster.dismiss" />)).toBe(`<span data-language="en" data-locale="${localeOf('en')}">toaster.dismiss</span>`);
    });
});

describe('shellOf', () => {
    it('reads the language and the shell section off any host\'s props', () => {
        expect(shellOf({ language: 'el', strings: { shell: KEYED_SHELL } })).toEqual({ language: 'el', strings: KEYED_SHELL });
        expect(shellOf({ strings: { form: {}, shell: { toaster: KEYED_SHELL.toaster } } })).toEqual({ language: 'en', strings: { toaster: KEYED_SHELL.toaster } });
    });

    it('reads English and no section where a page carries neither', () => {
        expect(shellOf({})).toEqual({ language: 'en', strings: null });
        expect(shellOf({ language: 'de', strings: 'shell' })).toEqual({ language: 'en', strings: null });
    });
});

describe('the keyed table', () => {
    it('puts every value\'s key in brackets, its placeholders after it', () => {
        expect(keyedTable({ a: { b: 'Plain', c: 'From :start to :end, :start again.' } })).toEqual({ a: { b: '[a.b]', c: '[a.c :start :end]' } });
        expect(keyedShell().text('banners.support.line', { operator: 'Pat', end: 'noon', zone: 'Athens' })).toBe('[banners.support.line Pat noon Athens]');
    });
});

describe('placed', () => {
    it('cuts a line at the placeholders it still holds and puts each one\'s node there, in the line\'s own order', () => {
        expect(renderToStaticMarkup(<p>{placed(':hours then tail', { hours: <a href="/shifts">44h</a> })}</p>)).toBe('<p><a href="/shifts">44h</a> then tail</p>');
        expect(renderToStaticMarkup(<p>{placed('from :start to :end', { end: <b>2</b>, start: <i>1</i> })}</p>)).toBe('<p>from <i>1</i> to <b>2</b></p>');
        expect(renderToStaticMarkup(<p>{placed('by :ends, not :end', { end: <b>e</b>, ends: <i>s</i> })}</p>)).toBe('<p>by <i>s</i>, not <b>e</b></p>');
    });

    it('wraps each run of words where a wrapper is given, and leaves a line holding no placeholder whole', () => {
        expect(renderToStaticMarkup(<p>{placed(':start then :end', { start: <i>1</i>, end: <b>2</b> }, (words) => <span>{words}</span>)}</p>)).toBe('<p><i>1</i><span> then </span><b>2</b></p>');
        expect(renderToStaticMarkup(<p>{placed('plain-token', { hours: <b>x</b> })}</p>)).toBe('<p>plain-token</p>');
    });
});
