<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\PasswordRule;
use App\Platform\Operator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Changes an operator's password: the way the sign-in notice names for an operator who did not
 * sign in, since the console never types or learns a password and nobody resets one there.
 *
 * The input is judged at the boundary as console:add-operator judges its own, the password by
 * PasswordRule. An email no operator holds is refused in one sentence; the comparison is the
 * column's own, citext, so an address differing only in case is the same address. The new
 * password is hashed by the model's cast, written to that operator's row alone and never printed.
 *
 * Writing the hash is all it does, and it is enough to end every console session of that operator
 * at its next request: each session carries the fingerprint of the password it signed in with, and
 * the operator check refuses one that no longer matches the row. The sessions table is neither
 * read nor written, since a console session's row names no operator.
 */
final class SetOperatorPasswordCommand extends Command
{
    public const string UNKNOWN = 'No operator holds that email.';

    protected $signature = 'console:set-operator-password
        {--email= : The operator\'s email, their sign-in at the console}
        {--password= : The operator\'s new password}';

    protected $description = 'Set a new password for an operator of the platform console, found by their email.';

    public function handle(): int
    {
        $input = [
            'email' => $this->text($this->option('email')),
            'password' => $this->text($this->option('password')),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', new PasswordRule],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $operator = Operator::query()->where('email', $input['email'])->first();

        if ($operator === null) {
            $this->error(self::UNKNOWN);

            return self::INVALID;
        }

        $operator->password = $input['password'];
        $operator->save();

        $this->info(sprintf('Set a new password for %s.', $operator->email));

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
