<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The help bubble's tickets: a business's questions to the platform and the platform's answers,
 * in three new tables of the central schema, so the console lists and sorts every business's
 * tickets in one read.
 *
 * help_tickets is one row per ticket, tied to its business by a reference with no cascade, as the
 * support sessions are. The business's side names a ticket by its uuid alone, which cannot be
 * guessed or counted; the console names it by its id. The subject is held to 150 characters and
 * the status to open or closed by CHECKs. last_message_at and last_side are the newest message's
 * instant and which side wrote it, the business or the platform, and last_platform_at the newest
 * platform message's instant, null until the platform writes: every message writes them in the
 * same transaction as its own row, and closing or reopening never moves them. The ticket is kept
 * twelve months from last_message_at, so the retention sweep deletes by that column alone, and the
 * drop deletes a business's tickets by tenant_id. The console lists open tickets before closed
 * ones and then the newest activity first, and help_tickets_status_activity_index is kept in that
 * very order, on whether the status is open rather than on the status, so a page of the list is
 * read off the index and nothing is sorted.
 *
 * help_messages is one row per message, deleted with its ticket. The writer is kept by side and
 * reference and by name as written: a business user's id and the name they wrote under, or an
 * operator's id and name. The user's id is a plain number and no reference, so removing a user from
 * the team keeps their messages under the name they wrote with; an operator is a reference, as in
 * the platform audit log, since an operator row is never deleted. The body is held to 5000
 * characters.
 *
 * help_reads is how far a user has read a thread: the instant of the newest message it held when
 * they last opened it, one row per user and ticket, deleted with the ticket and with the user: a
 * read mark means nothing once its reader is gone. A ticket is unread for a user while its
 * last_platform_at is later than that user's read_at, or there is no read row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table help_tickets (
                id bigserial primary key,
                uuid uuid not null,
                tenant_id bigint not null references tenants (id),
                subject text not null,
                status text not null default 'open',
                last_message_at timestamptz not null,
                last_side text not null,
                last_platform_at timestamptz,
                created_at timestamptz not null,
                updated_at timestamptz not null,
                constraint help_tickets_uuid_unique unique (uuid),
                constraint help_tickets_subject_check check (char_length(subject) between 1 and 150),
                constraint help_tickets_status_check check (status in ('open', 'closed')),
                constraint help_tickets_last_side_check check (last_side in ('business', 'platform'))
            );

            create index help_tickets_tenant_activity_index on help_tickets (tenant_id, last_message_at desc, id desc);
            create index help_tickets_status_activity_index on help_tickets ((status = 'open') desc, last_message_at desc, id desc);
            create index help_tickets_last_message_at_index on help_tickets (last_message_at);

            create table help_messages (
                id bigserial primary key,
                ticket_id bigint not null references help_tickets (id) on delete cascade,
                side text not null,
                user_id bigint,
                operator_id bigint references operators (id),
                author_name text not null,
                body text not null,
                created_at timestamptz not null,
                constraint help_messages_side_check check (side in ('business', 'platform')),
                constraint help_messages_author_check check (
                    (side = 'business' and user_id is not null and operator_id is null)
                    or (side = 'platform' and operator_id is not null and user_id is null)
                ),
                constraint help_messages_body_check check (char_length(body) between 1 and 5000)
            );

            create index help_messages_ticket_index on help_messages (ticket_id, created_at, id);
            create index help_messages_user_index on help_messages (user_id, created_at) where user_id is not null;

            create table help_reads (
                ticket_id bigint not null references help_tickets (id) on delete cascade,
                user_id bigint not null references users (id) on delete cascade,
                read_at timestamptz not null,
                primary key (ticket_id, user_id)
            );

            create index help_reads_user_index on help_reads (user_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists help_reads; drop table if exists help_messages; drop table if exists help_tickets;');
    }
};
