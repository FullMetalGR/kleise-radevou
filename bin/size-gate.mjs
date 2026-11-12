#!/usr/bin/env node
/*
 * The size gate: the byte budgets for the booking app plus the externals contract. The embed
 * runs on somebody else's page, so its budget is a promise to a third party rather than a
 * preference, and the budget is recorded in bundle-budget.json rather than here so that
 * tightening it is a one-line diff. The build writes a record of what it declared external
 * beside the bundle, and any entry there fails this gate: a bundle that leaves React to the
 * host page would pass a byte budget and break the first tenant page without it.
 *
 * Usage: node bin/size-gate.mjs
 */
import { existsSync, readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_FILE = 'bundle-budget.json';
const BUILD_RECORD = 'public/build/embed.build.json';

function fail(message) {
    console.error(`size: ${message}`);
    process.exitCode = 1;
}

function readJson(path) {
    if (!existsSync(path)) {
        throw new Error(`${path} is missing; run npm run build first.`);
    }

    return JSON.parse(readFileSync(path, 'utf8'));
}

const budgets = readJson(BUDGET_FILE);
const record = readJson(BUILD_RECORD);

if (record.format !== 'iife') {
    fail(`the bundle is built as "${record.format}", not as the classic script a tenant pastes.`);
}

if (!Array.isArray(record.externals) || record.externals.length > 0) {
    fail(`the bundle declares externals: ${JSON.stringify(record.externals)}. The embed carries everything it runs on and takes nothing from the host page.`);
}

for (const [file, budget] of Object.entries(budgets)) {
    if (!Number.isInteger(budget) || budget <= 0) {
        fail(`${file}: the budget in ${BUDGET_FILE} must be a positive integer of bytes, not ${JSON.stringify(budget)}.`);
        continue;
    }

    if (!existsSync(file)) {
        fail(`${file} is missing; run npm run build first.`);
        continue;
    }

    const size = statSync(file).size;
    const gzipped = gzipSync(readFileSync(file)).length;
    const verdict = size <= budget ? 'ok' : 'OVER BUDGET';

    console.log(`size: ${file} ${size} bytes (${gzipped} gzipped) of ${budget} budgeted: ${verdict}`);

    if (size > budget) {
        fail(`${file} exceeds its budget by ${size - budget} bytes.`);
    }
}

if (process.exitCode === 1) {
    console.error('size: failing the build.');
} else {
    console.log('size: within budget, no externals.');
}
