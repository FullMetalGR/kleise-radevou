#!/usr/bin/env node
/*
 * The enforcing half of the fallow gate: static analysis over the JS and TS surface, failing
 * on error findings. fallow writes its findings as SARIF; this reads that file and exits
 * non-zero when any result sits at level "error", so the bar holds whatever exit code fallow
 * itself chose. Warnings and notes stay advisory and are counted, not fatal.
 *
 * Usage: node bin/fallow-gate.mjs <sarif-file>
 */
import { readFileSync } from 'node:fs';

const path = process.argv[2];

if (path === undefined) {
    console.error('usage: node bin/fallow-gate.mjs <sarif-file>');
    process.exit(2);
}

const sarif = JSON.parse(readFileSync(path, 'utf8'));
const results = (sarif.runs ?? []).flatMap((run) => run.results ?? []);
const errors = results.filter((result) => result.level === 'error');

for (const result of results) {
    const physical = result.locations?.[0]?.physicalLocation;
    const file = physical?.artifactLocation?.uri ?? '(project)';
    const line = physical?.region?.startLine ?? 0;

    console.error(`${file}:${line}: ${result.level ?? 'note'} [${result.ruleId}] ${result.message?.text ?? ''}`);
}

console.error(`fallow: ${results.length} finding(s), ${errors.length} at level "error"${errors.length === 0 ? '.' : '; failing the build.'}`);
process.exit(errors.length === 0 ? 0 : 1);
