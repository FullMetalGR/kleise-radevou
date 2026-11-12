<?php

declare(strict_types=1);

namespace App\Application\Payment;

use SensitiveParameter;

/**
 * Saving own-mode credentials: the connection is written as the tenant's one connection,
 * restricted until a check passes, and then checked once. A pass lands it active; a fail keeps
 * it saved, restricted, with the provider's reason on the row. So the tenant's provider is the
 * null one until a check has passed.
 *
 * The write commits before the provider is called, so no provider is called inside a
 * transaction, and whatever the check does, the credentials stay saved.
 *
 * The webhook token is kept where the tenant is replacing the credentials of the provider it is
 * already connected to in own mode, so the URL pasted into that provider's dashboard goes on
 * working; any other save mints a new one. Rotating is the way to change it, and touches no
 * credential.
 */
final readonly class ConnectOwnMode
{
    public function __construct(private TestConnection $test) {}

    /**
     * @param  array<string, string>  $fields  the provider's declared fields by name, validated at the boundary
     * @return ConnectionCheck|null the check landed, null where none could run
     *
     * @throws ConnectionModeTaken where the provider is connected through us
     */
    public function connect(PaymentConnections $connections, ProviderDescriptor $descriptor, #[SensitiveParameter] array $fields): ?ConnectionCheck
    {
        $current = $connections->primary();
        if ($current !== null && $current->provider === $descriptor->slug && $current->mode === ConnectionMode::Platform) {
            throw ConnectionModeTaken::by($current, $descriptor->name);
        }
        $same = $current !== null && $current->provider === $descriptor->slug && $current->mode === ConnectionMode::Own;
        $declared = [];

        foreach ($descriptor->credentialFields as $field) {
            $declared[$field->name] = $fields[$field->name] ?? '';
        }

        $connections->write(new NewConnection(
            $descriptor->slug,
            ConnectionMode::Own,
            ConnectionState::Restricted,
            $declared,
            null,
            ($same ? $connections->webhookToken() : null) ?? WebhookToken::mint(),
        ));

        return $this->test->test($connections);
    }
}
