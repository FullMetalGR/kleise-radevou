<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Site\Demo;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * The sign-in form, and the console's door's. The shape only: whether the pair names an account at
 * this tenant, or an operator, is the controller's one refusal, so this says nothing about whether
 * an address exists.
 *
 * Its four refusals are the form's own messages, hand-drafted keys of lang/{en,el}/admin/shell.php
 * under validation.login, so no framework line reaches a user untranslated: read in the request's
 * language on a tenant host, the tenant default before a sign-in, and in English on the console's
 * door, whose copy stays English.
 *
 * On the demo's door a refused form goes back with its errors and none of its input, so no
 * sessions row of the demo holds what a visitor typed; every other door flashes the input back as
 * the framework does.
 */
final class LoginRequest extends FormRequest
{
    /** The keys of the four lines, by the field and the rule each answers. */
    public const array MESSAGES = [
        'email.required' => 'admin/shell.validation.login.email_required',
        'email.string' => 'admin/shell.validation.login.email_string',
        'password.required' => 'admin/shell.validation.login.password_required',
        'password.string' => 'admin/shell.validation.login.password_string',
    ];

    /** The console's routes, whose door reads the lines in English. */
    private const string CONSOLE_ROUTES = 'console.*';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $language = $this->routeIs(self::CONSOLE_ROUTES) ? SiteLanguage::English->value : null;

        return array_map(function (string $key) use ($language): string {
            $line = __($key, [], $language);

            return is_string($line) ? $line : $key;
        }, self::MESSAGES);
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $this->routeIs(self::CONSOLE_ROUTES) && app(Demo::class)->is(app(CurrentTenant::class)->get())) {
            throw new ValidationException($validator, redirect()->to($this->getRedirectUrl())->withErrors($validator->errors(), $this->errorBag));
        }

        parent::failedValidation($validator);
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
