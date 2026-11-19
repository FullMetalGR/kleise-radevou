import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Every screen inside the shell opens with the shared page header, which writes the page's one
 * main heading, so no screen writes a heading of that rank of its own. The pages a guest sees
 * before signing in sit outside the shell and keep their own.
 */

const ADMIN = join(process.cwd(), 'resources/js/admin');

/** The one part allowed to write the main heading, and the guest pages outside the shell. */
const ALLOWED = [/^components\/ui\/page-header\.tsx$/, /^pages\/auth\//, /^pages\/signup\//, /^pages\/console\/Login\.tsx$/];

function sourcesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            return sourcesUnder(path);
        }

        return name.endsWith('.tsx') && !name.endsWith('.test.tsx') ? [path] : [];
    });
}

describe('the main heading', () => {
    it('no screen inside the shell writes an h1 of its own', () => {
        const offenders = sourcesUnder(ADMIN)
            .map((path) => relative(ADMIN, path).split('\\').join('/'))
            .filter((path) => !ALLOWED.some((allowed) => allowed.test(path)))
            .filter((path) => readFileSync(join(ADMIN, path), 'utf8').includes('<h1'));

        expect(offenders).toEqual([]);
    });

    it('is written by the shared page header', () => {
        expect(readFileSync(join(ADMIN, 'components/ui/page-header.tsx'), 'utf8')).toContain('<h1');
    });
});
