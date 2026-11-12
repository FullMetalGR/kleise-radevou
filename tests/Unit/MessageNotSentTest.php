<?php

use App\Jobs\MessageNotSent;

/*
 * What a job holding the customer's credential is allowed to fail with.
 *
 * A failed job's exception column keeps the stringified throwable, and a stringified throwable
 * prints the arguments of every frame in its trace. The manage link is an argument: the job
 * builds it and hands it to the message. So the guard is that nothing from below the job's own
 * frame is ever rendered, and this is where that is held to.
 *
 * Two settings of the engine decide what a trace prints, and both are pinned here rather than
 * inherited from whichever php.ini the box runs. zend.exception_ignore_args renders the
 * arguments at all: it is off by the engine's default, and php.ini-production turns it on,
 * under which a trace prints no argument and the hazard below cannot be shown.
 * zend.exception_string_param_max_len truncates each string argument, to fifteen characters by
 * default, which would hide a token by accident. The first is set off and the second raised to
 * the largest it takes, so what passes is the guard and not the environment.
 */

/**
 * Something going wrong under a frame that is holding the link, which is the shape of every
 * failure this guard is for.
 */
function deepFailure(string $link): never
{
    throw new RuntimeException('the send came apart');
}

it('carries nothing from the frames below it, whatever the engine is told to print', function () {
    $wasIgnoringArgs = ini_get('zend.exception_ignore_args');
    $wasTruncatingAt = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000');

    try {
        $token = str_repeat('a1b2c3d4', 8);
        $uuid = '7b7f0e5a-0000-4000-8000-000000000001';
        $link = 'https://luna.example.test/manage/'.$uuid.'?token='.$token;
        $raw = '';
        $refused = '';

        try {
            deepFailure($link);
        } catch (Throwable $failure) {
            $raw = (string) $failure;
            $refused = (string) MessageNotSent::after($failure, $uuid);
        }

        // The raw throwable says nothing about the link and prints it anyway, because the
        // frame that was holding it is in the trace and its arguments are rendered. That is
        // the whole of the hazard, and it is asserted rather than described so that the guard
        // below it can never be deleted as precaution against nothing.
        expect($raw)->toContain($link)
            ->and($refused)->not->toContain($token)
            ->and($refused)->not->toContain($link)
            ->and($refused)->not->toContain('the send came apart')
            ->and($refused)->toContain(RuntimeException::class)
            ->and($refused)->toContain($uuid)
            ->and($refused)->toContain(basename(__FILE__));
    } finally {
        ini_set('zend.exception_ignore_args', is_string($wasIgnoringArgs) ? $wasIgnoringArgs : '0');
        ini_set('zend.exception_string_param_max_len', is_string($wasTruncatingAt) ? $wasTruncatingAt : '15');
    }
});

it('names where the fault happened, so a class and a line is what an operator is left with', function () {
    $failure = new RuntimeException('a mail host having a bad afternoon');
    $line = __LINE__ - 1;

    expect(MessageNotSent::after($failure, '7b7f0e5a-0000-4000-8000-000000000001')->getMessage())
        ->toBe(sprintf(
            'RuntimeException was thrown at %s line %d while sending a message about booking 7b7f0e5a-0000-4000-8000-000000000001.',
            __FILE__,
            $line,
        ));
});

it('names the link by its id where the message is the bookings link, which is about no booking', function () {
    $failure = new RuntimeException('a mail host having a bad afternoon');
    $line = __LINE__ - 1;

    expect(MessageNotSent::after($failure, '42', MessageNotSent::ABOUT_LINK)->getMessage())
        ->toBe(sprintf('RuntimeException was thrown at %s line %d while sending a message about link 42.', __FILE__, $line));
});

it('chains nothing, because a previous exception is rendered with the trace this refuses', function () {
    expect(MessageNotSent::after(new RuntimeException('below'), '7b7f0e5a-0000-4000-8000-000000000001')->getPrevious())->toBeNull();
});
