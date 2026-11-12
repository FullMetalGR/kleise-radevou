<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * The retention table, as the lines retention:sweep runs and the order it runs them in: the
 * central lines first; then the tenant lines, every one of them over each built schema in turn,
 * the customer before the intake rows it takes with it and the trail, the token and My bookings'
 * rows after, and a customer's profile and notes last, which go once no booking carries their
 * address; then the stranded rows, each run once, the lapsed subscribers closed and then the
 * checkout records past their lifetime cleared; the drop last. The lapsed subscribers' line is a
 * close per row through the one writer rather than one statement, and the drop is no line of this
 * table's shape either, but a transaction per tenant of the statements it takes
 * (App\Platform\TenantDrop), and retention:sweep runs it after every line here.
 */
final readonly class RetentionSweep
{
    /** @var list<RetentionLine> */
    private array $lines;

    /** @var list<TenantLine> */
    private array $tenantLines;

    /** @var list<RetentionLine> */
    private array $strandedLines;

    public function __construct(
        ContactMessagesLine $contactMessages,
        SupportSessionsLine $supportSessions,
        PasswordLinksLine $passwordLinks,
        PlatformAuditLine $platformAudit,
        WebhookEventsLine $webhookEvents,
        PaymentEventsLine $paymentEvents,
        BookingCustomersLine $bookingCustomers,
        BookingMetaLine $bookingMeta,
        AuditLogLine $auditLog,
        ManageTokensLine $manageTokens,
        CustomerLinksLine $customerLinks,
        CustomerSessionsLine $customerSessions,
        CustomerProfilesLine $customerProfiles,
        CustomerNotesLine $customerNotes,
        LapsedSubscribersLine $lapsedSubscribers,
        CheckoutRecordsLine $checkoutRecords,
    ) {
        $this->lines = [$contactMessages, $supportSessions, $passwordLinks, $platformAudit, $webhookEvents, $paymentEvents];
        $this->tenantLines = [$bookingCustomers, $bookingMeta, $auditLog, $manageTokens, $customerLinks, $customerSessions, $customerProfiles, $customerNotes];
        $this->strandedLines = [$lapsedSubscribers, $checkoutRecords];
    }

    /**
     * The central lines, each run once.
     *
     * @return list<RetentionLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * The tenant lines, each run once over every built schema.
     *
     * @return list<TenantLine>
     */
    public function tenantLines(): array
    {
        return $this->tenantLines;
    }

    /**
     * The stranded rows' lines, each run once after the tenant lines and before the drop.
     *
     * @return list<RetentionLine>
     */
    public function strandedLines(): array
    {
        return $this->strandedLines;
    }
}
