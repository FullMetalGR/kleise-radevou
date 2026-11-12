import type { KeysOf, Strings } from '@/lib/strings';
import type { ReportsStrings } from '@/types';

/*
 * Reports' words: the `reports` section of lang/{locale}/admin, which ReportController names
 * beside the shell's, read by key in the page's language. Every sentence of Reports is one of its
 * keys; no component under reports/ spells one of its own.
 */

export type ReportsKey = KeysOf<ReportsStrings>;

export type ReportsWords = Strings<ReportsKey>;

