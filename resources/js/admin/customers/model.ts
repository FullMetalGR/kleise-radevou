import type { Prefill } from '@/wizard/model';
import type { CustomersKey } from './words';

/*
 * The Customers screen's data as the server sends it, CustomerController. A customer is what
 * CustomerPayload::admin makes of one address for this viewer: a key the viewer may not see, the
 * email and the phones under the CONTACT rule, is absent, never null. The visits are instants on
 * the wire, each a booking's first start.
 *
 * The page's tiles ride on the same row: the bookings upcoming (confirmed, awaiting approval or
 * awaiting payment, the first start ahead of now), completed, no-shows and cancelled, and the
 * last visit, the latest first start already passed in confirmed or completed, null where none
 * is. Beside them the address's profile as the name cell and the page's head draw it: its tags
 * and whether it is blocked.
 */

export type Customer = {
    /** The most recent name the address booked under: the row's own name. */
    name: string;
    /** Every name seen, each once, the most recent first. */
    names: string[];
    email?: string;
    /** Every phone seen, each once, the most recent first. */
    phones?: string[];
    first_visit: string;
    /** The latest first start already passed in confirmed or completed, null where none is. */
    last_visit: string | null;
    bookings: number;
    upcoming: number;
    completed: number;
    no_shows: number;
    cancelled: number;
    /** The customer's page, the address percent-encoded as one segment. */
    url: string;
    /** The profile's tags, each once in the order written; none where no profile is kept. */
    tags: string[];
    /** Whether online booking is blocked for the address. */
    blocked: boolean;
};

/** What the Profile card edits, the page's profile prop: a birthday is a machine date. */
export type Profile = { tags: string[]; birthday: string | null; language: 'el' | 'en' | null };

/** One of the staff's notes on the address, the page's notes prop newest first: created_at an instant. */
export type Note = { id: number; body: string; author: string; created_at: string };

/** The block on the address's online booking, the page's block prop: both null where none stands. */
export type Block = { blocked_at: string | null; reason: string | null };

/** What the address's visits came to in one currency, the page's Spent line. */
export type Spent = { amount_minor: number; currency: string };

export type CustomerPage = { rows: Customer[]; total: number; page: number; page_size: number };

/** The list's query as the URL carries it; the screen remembers nothing else. */
export type CustomerQuery = { search: string; page: number };

/** The screen's title, the table's head and the breadcrumb's link, a key of the customers section. */
export const CUSTOMERS_TITLE = 'page.title' satisfies CustomersKey;

/**
 * The query string of a search and a page, the defaults left out, so the first page of no search
 * is the bare list.
 */
export function customerQuery(search: string, page: number): Record<string, string> {
    const query: Record<string, string> = {};

    if (search.trim() !== '') {
        query['search'] = search;
    }

    if (page > 1) {
        query['page'] = String(page);
    }

    return query;
}

/**
 * The list's Download CSV for the search the screen answered: the whole filtered list, so the
 * page is never carried, and the bare address where no search narrows it.
 */
export function customersCsvUrl(search: string): string {
    const query = new URLSearchParams(customerQuery(search, 1)).toString();

    return query === '' ? '/admin/customers/csv' : `/admin/customers/csv?${query}`;
}

/** Whether the server showed this viewer the ways to reach its customers, read off the rows themselves. */
export function contactShown(rows: readonly Customer[]): boolean {
    return rows.some((row) => row.email !== undefined);
}

/** A customer's last visit where none is yet: the list's cell and the page's tile, a key. */
export const NO_VISIT = 'table.no_visit' satisfies CustomersKey;

/**
 * The customer Book now opens the wizard with, its Prefill.customer: the most recent name, the
 * email, the most recent phone.
 */
export function bookingCustomer(customer: Customer): NonNullable<Prefill['customer']> {
    return { name: customer.name, email: customer.email ?? '', phone: customer.phones?.[0] ?? '' };
}
