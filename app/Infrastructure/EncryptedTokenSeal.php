<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Payment\ManageTokenSeal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use SensitiveParameter;

/**
 * The manage token sealed with the framework's encrypter, the key every encrypted queue payload
 * is sealed with, so the token is at rest nowhere in a form the queue's own payload would not
 * already have it in.
 */
final readonly class EncryptedTokenSeal implements ManageTokenSeal
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function seal(#[SensitiveParameter] string $plain): string
    {
        return $this->encrypter->encryptString($plain);
    }

    public function open(string $sealed): ?string
    {
        try {
            return $this->encrypter->decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }
}
