<?php

/*
 * The Payments section of the Settings screen in English: the section's words and the server's
 * sentences behind it, shared as the strings prop's payments section, which SettingsController
 * names beside the settings section, in the language of the request, the user's. Its Greek twin is
 * lang/el/admin/payments.php.
 *
 * :provider is a provider's name as its descriptor declares it, a proper noun never translated.
 * What a provider says or shows is the provider's and never passes through here.
 *
 * waits are the sentences that hold the other controls while one of the section's eight writes is
 * in flight. degrade is the line while nothing is connected, cash the line saying paying on site
 * needs no connection, not_offered a mode the prop does not offer, gone the 404 of a write that
 * met no connection, worded here, and error a write that reached no answer.
 *
 * modes is how each mode connects. cost is what a mode costs, one whole sentence per case: own
 * mode, platform mode taking nothing, a figure (:fee), a mode offered with no figure known, and
 * the fee in force on a connection made through us, nothing or a figure. :rate is where the
 * provider's own processing rate, cost.rate, sits as a link. In all six cost lines :rate is the
 * subject of "applies" and carries the link, so a translator whose phrase opens with an article
 * reorders the line around it. cost.fee joins a fee's percentage
 * (:percent, in the user's language) and its fixed part (:fixed, the tenant's money).
 *
 * connected is the line under a connection's provider by its mode; states is the state's sentence;
 * information is the line under an active row made through us whose provider still lists
 * information it needs, and give its link. checked is the last test of an own-mode row, one
 * whole sentence per outcome, :when the instant on the tenant's clock in the user's language.
 *
 * reasons are the sentences of ours a failed connection test records, quoted under checked.failed,
 * one whole line per way it fails, each named by the key the check records beside its English: the
 * test stopped on a throwable it did not expect (:class, the class thrown), no secret key stored,
 * Stripe unreachable, and Stripe answering an error with no message of its own (:status, the HTTP
 * status) or with one (:reason, Stripe's words as sent and struck of the connection's secrets,
 * never translated). answered_message ends on :reason with no full stop of its own, the provider's
 * words bringing theirs. :class, :status and :reason are names of the kind the placeholder rule
 * gives a value its vocabulary does not name, lower-case and the same in both languages; :reason
 * is the one the admin's other files already use for a reason quoted.
 *
 * standing is where a row made through us last stood with its provider, one whole sentence per
 * outcome, :when as in checked.
 *
 * buttons are the section's controls and their busy words. credentials is the own-mode form: its
 * heading, a replacement's heading and help, and the stored secrets' heading. disconnect and
 * webhook are Disconnect's confirm and the webhook URL with its copy and its change, the change's
 * confirm among them; Keep it is the kit's (shell.kit.confirm.keep).
 *
 * validation is PaymentCredentialsRequest's and PlatformConnectRequest's, :field a credential
 * field's label in the request's language; missing and too_long are stated by the form before a
 * save in the same words. refusals are PaymentsController's 409s: two saves at once, a mode taken
 * by the other mode, and the onboarding asked for while connecting through us is not set up.
 * flashes are the writes' answers.
 *
 * fields are our words for a provider's credential fields, under the provider's slug and the
 * field's name, each its label and its help, which the descriptor names by key.
 */

return [
    'waits' => [
        'connect' => 'Please wait until the payment credentials have been saved and tested.',
        'platform' => 'Please wait until the provider\'s onboarding opens.',
        'resume' => 'Please wait until the provider\'s onboarding opens again.',
        'test' => 'Please wait until the connection test has finished.',
        'rotate' => 'Please wait until the webhook URL has been changed.',
        'disable' => 'Please wait until online payments have been switched off.',
        'enable' => 'Please wait until online payments have been switched back on.',
        'disconnect' => 'Please wait until the payment provider has been disconnected.',
    ],
    'degrade' => 'Online services take bookings and collect payment in person until a payment provider is connected.',
    'cash' => 'Paying on site needs no connection: a service set to be paid on site works the same whatever is set here, so connecting a provider is never required.',
    'not_offered' => 'Not offered yet.',
    'gone' => 'There is no payment connection to change any more. Reload the page to see where it stands.',
    'error' => 'The payment settings could not be changed. Check your connection and try again.',
    'modes' => [
        'platform' => 'Connect through us',
        'own' => 'Use your own credentials',
    ],
    'cost' => [
        'own' => 'Costs nothing: :rate applies.',
        'nothing' => 'One button and you are done. We take nothing from each transaction; :rate applies.',
        'figure' => 'One button and you are done. We take :fee from each transaction; :rate applies.',
        'unstated' => 'One button and you are done. :rate applies.',
        'in_force' => 'We take nothing from each transaction; :rate applies.',
        'in_force_figure' => 'We take :fee from each transaction; :rate applies.',
        'rate' => ':provider\'s own processing rate',
        'fee' => ':percent plus :fixed',
    ],
    'connected' => [
        'own' => 'Connected with your own credentials.',
        'platform' => 'Connected through us.',
    ],
    'states' => [
        'pending' => 'Onboarding with :provider is unfinished. Online services collect payment in person until it is done.',
        'active' => 'Connected to :provider and taking payments.',
        'restricted' => ':provider needs something from you before it can take payments. Online services collect payment in person until then.',
        'disabled' => 'Switched off here. Online services collect payment in person until you switch it back on.',
    ],
    'information' => ':provider is taking payments but still needs some information from you.',
    'give' => 'Give :provider the information',
    'checked' => [
        'never' => 'Not tested yet.',
        'passed' => 'Last tested :when: the test passed.',
        'failed' => 'Last tested :when: the test failed.',
        'unknown' => 'Last tested :when.',
    ],
    'reasons' => [
        'unexpected' => 'The connection test stopped on an unexpected :class.',
        'no_secret_key' => 'The connection holds no secret key.',
        'unreachable' => 'Stripe did not answer the test: Stripe could not be reached.',
        'answered_status' => 'Stripe did not answer the test: Stripe answered HTTP :status.',
        'answered_message' => 'Stripe did not answer the test: :reason',
    ],
    'standing' => [
        'never' => 'Nothing heard from :provider yet.',
        'taking' => 'Last heard from :provider :when: taking payments.',
        'outstanding' => 'Last heard from :provider :when: something is outstanding.',
        'unfinished' => 'Last heard from :provider :when: the onboarding is unfinished.',
    ],
    'buttons' => [
        'own' => 'Enter :provider credentials',
        'platform' => 'Connect :provider through us',
        'opening' => 'Opening the onboarding',
        'save' => 'Save and test',
        'saving' => 'Saving and testing',
        'cancel' => 'Cancel',
        'resume' => 'Resume onboarding',
        'test' => 'Test connection',
        'testing' => 'Testing',
        'replace' => 'Replace credentials',
        'enable' => 'Switch back on',
        'enabling' => 'Switching on',
        'disable' => 'Switch off',
        'disabling' => 'Switching off',
    ],
    'credentials' => [
        'title' => 'Your :provider credentials',
        'replace_title' => 'Replace your :provider credentials',
        'replace' => 'Enter every field again: a save replaces the whole set, and stored values are never shown.',
        'stored' => 'Stored, and never shown again:',
    ],
    'disconnect' => [
        'button' => 'Disconnect',
        'title' => 'Disconnect :provider?',
        'body' => 'Online services go back to collecting payment in person, bookings already awaiting payment can no longer be paid online, and the webhook URL stops working. The stored credentials are removed, so connecting again means entering them again.',
        'busy' => 'Disconnecting',
    ],
    'webhook' => [
        'title' => 'Webhook URL',
        'help' => 'Paste it into your :provider dashboard so payments are confirmed here. Changing it leaves the credentials alone; paste the new one in after.',
        'copy' => 'Copy URL',
        'copy_failed' => 'Copying failed. Select the URL and copy it yourself.',
        'change' => 'Change URL',
        'changing' => 'Changing',
        'confirm_title' => 'Change the webhook URL?',
        'confirm_body' => 'The current URL stops working at once, so :provider cannot confirm payments here until you paste the new one into your :provider dashboard. The credentials, the state and the last test stay as they are.',
    ],
    'validation' => [
        'no_provider' => 'Choose the payment provider to connect.',
        'not_own' => 'That payment provider cannot be connected with your own credentials.',
        'not_platform' => 'That payment provider cannot be connected through us.',
        'missing' => ':field is required.',
        'too_long' => ':field is longer than any credential the provider issues.',
        'unreadable' => ':field is not readable text.',
    ],
    'refusals' => [
        'contended' => 'Another save changed the payment connection at the same moment. Please reload the page and check it before saving again.',
        'taken_own' => ':provider is connected with your own credentials. Disconnect it first, then connect it through us.',
        'taken_platform' => ':provider is connected through us. Disconnect it first, then enter your own credentials.',
        'unavailable' => 'The onboarding cannot be opened right now, because connecting through us is not set up on our side. Your connection stands as it is.',
    ],
    'flashes' => [
        'saved' => 'Payment credentials saved. The connection test passed.',
        'saved_unchecked' => 'Payment credentials saved, but the connection test failed.',
        'test_passed' => 'The connection test passed.',
        'test_failed' => 'The connection test failed.',
        'rotated' => 'Webhook URL changed. Paste the new one into your provider\'s dashboard.',
        'disabled' => 'Online payments switched off.',
        'enabled' => 'Online payments switched back on.',
        'disconnected' => 'Payment provider disconnected.',
        'onboarding_failed' => 'The provider could not start the onboarding. Nothing was charged; try again in a moment.',
    ],
    'fields' => [
        'stripe' => [
            'secret_key' => [
                'label' => 'Secret key',
                'help' => 'The secret API key of your Stripe account, from its Developers page. A restricted key works where it may write Checkout Sessions and PaymentIntents.',
            ],
            'webhook_secret' => [
                'label' => 'Webhook signing secret',
                'help' => 'The signing secret Stripe shows for the webhook endpoint you add with the address below, sending the checkout.session.completed and checkout.session.async_payment_succeeded events.',
            ],
        ],
    ],
];
