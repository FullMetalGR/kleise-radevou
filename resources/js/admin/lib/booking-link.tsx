import type { ReactNode } from 'react';
import { COPY_BOOKING_LINK, CopyLink } from './copy';

/*
 * The Copy booking link button of a service's and a member's detail page: the row's link as the
 * server spelled it, BookingLinks, copied, and the press recorded for the setup checklist where
 * the viewer may record it, manageSettings. A row the server handed no link for, the implicit
 * member, gets no button.
 */

export type BookingLink = { id: number; link: string };

export function copyLinkOf(links: readonly BookingLink[], id: number, permissions: readonly string[]): ReactNode {
    const link = links.find((candidate) => candidate.id === id)?.link;

    return link === undefined ? null : <CopyLink link={link} label={COPY_BOOKING_LINK} block="admin-booking-link" records={permissions.includes('manageSettings')} />;
}
