<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\PasswordRule;
use App\Platform\Operator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Adds an operator to the platform's console. Nobody registers: no route creates an operator, and
 * this command is the one way a row reaches the table.
 *
 * The input is judged at the boundary, the password by PasswordRule as the signup's is, where
 * tenant:provision and tenant:add-user keep their plain rules. An email an operator already holds
 * is refused in one sentence; the comparison is the column's own, citext, so an address differing
 * only in case is the same address. The password is never printed.
 */
final class AddOperatorCommand extends Command
{
    public const string HELD = 'An operator already holds that email.';

    protected $signature = 'console:add-operator
        {--name= : The operator\'s name}
        {--email= : The operator\'s email, their sign-in at the console}
        {--password= : The operator\'s password}';

    protected $description = 'Add an operator to the platform console with a name, an email and a password.';

    public function handle(): int
    {
        $input = [
            'name' => $this->text($this->option('name')),
            'email' => $this->text($this->option('email')),
            'password' => $this->text($this->option('password')),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', new PasswordRule],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        if (Operator::query()->where('email', $input['email'])->exists()) {
            $this->error(self::HELD);

            return self::INVALID;
        }

        Operator::query()->create($input);

        $this->info(sprintf('Added %s as an operator.', $input['email']));

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
