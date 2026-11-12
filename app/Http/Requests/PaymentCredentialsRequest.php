<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use App\Http\AdminLines;
use Closure;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The body of POST /admin/settings/payments: the slug of a provider the registry holds and offers
 * own mode for, and under credentials one string for every field its descriptor declares, each
 * required, since the store keeps them as one sealed object and a save is the whole set, and each
 * readable text, UTF-8, which only a crafted request fails to send: the sealed object is JSON and
 * the credential strike reads UTF-8, so neither meets a value that is not. A field the descriptor
 * does not declare is dropped, never stored.
 *
 * Refused as every admin write refuses a field, the 400 envelope carrying the first failing
 * field's sentence. No sentence repeats a value, so a refusal never carries a secret back. The
 * sentences are keys of lang/{locale}/admin/payments.php under validation, worded in the
 * request's language, a field's :field its label as the descriptor names it by key, read in the
 * same language. Nothing here decides who may post: the route declares manageSettings and the
 * subscription gate.
 */
final class PaymentCredentialsRequest extends AdminWriteRequest
{
    public const string PROVIDER = 'provider';

    public const string CREDENTIALS = 'credentials';

    /** The longest value a credential field takes. */
    public const int LONGEST = 1000;

    public const string NO_PROVIDER = 'admin/payments.validation.no_provider';

    public const string NOT_OWN = 'admin/payments.validation.not_own';

    public const string MISSING = 'admin/payments.validation.missing';

    public const string TOO_LONG = 'admin/payments.validation.too_long';

    public const string UNREADABLE = 'admin/payments.validation.unreadable';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            self::PROVIDER => ['required', 'string', Rule::in(array_map(
                fn (ProviderDescriptor $descriptor): string => $descriptor->slug,
                array_values(array_filter($this->registry()->descriptors(), fn (ProviderDescriptor $descriptor): bool => $descriptor->offers(ConnectionMode::Own))),
            ))],
        ];

        $descriptor = $this->declared();

        foreach ($descriptor === null ? [] : $descriptor->credentialFields as $field) {
            $rules[self::CREDENTIALS.'.'.$field->name] = ['required', 'string', 'max:'.self::LONGEST, self::readable(self::line($field->label))];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            self::PROVIDER.'.required' => self::line(self::NO_PROVIDER),
            self::PROVIDER.'.string' => self::line(self::NO_PROVIDER),
            self::PROVIDER.'.in' => self::line(self::NOT_OWN),
        ];

        $descriptor = $this->declared();

        foreach ($descriptor === null ? [] : $descriptor->credentialFields as $field) {
            $label = ['field' => self::line($field->label)];
            $messages[self::CREDENTIALS.'.'.$field->name.'.required'] = self::line(self::MISSING, $label);
            $messages[self::CREDENTIALS.'.'.$field->name.'.string'] = self::line(self::MISSING, $label);
            $messages[self::CREDENTIALS.'.'.$field->name.'.max'] = self::line(self::TOO_LONG, $label);
        }

        return $messages;
    }

    /**
     * A key of payments.php in the request's language, its placeholders filled; the section's
     * one reader on the server, the controller's flashes and refusals and the screen's prop
     * among its callers. A descriptor's words name their keys, and a provider that names
     * words of its own, no key, reads them as they are. It answers the admin's one reader,
     * AdminLines.
     *
     * @param  array<string, string>  $values
     */
    public static function line(string $key, array $values = []): string
    {
        return AdminLines::line($key, $values);
    }

    /**
     * The descriptor of the provider the body names, validated.
     */
    public function descriptor(): ProviderDescriptor
    {
        return $this->declared() ?? throw new LogicException('The payment provider was validated and the registry does not hold it.');
    }

    /**
     * The declared fields by name, as validated.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->descriptor()->credentialFields as $field) {
            $value = $this->input(self::CREDENTIALS.'.'.$field->name);
            $fields[$field->name] = is_string($value) ? $value : '';
        }

        return $fields;
    }

    /**
     * The rule that a value is readable text, UTF-8, refused with the field's own sentence, the
     * label already in the request's language.
     */
    private static function readable(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(self::line(self::UNREADABLE, ['field' => $label]));
            }
        };
    }

    private function declared(): ?ProviderDescriptor
    {
        $slug = $this->input(self::PROVIDER);
        $descriptor = is_string($slug) ? $this->registry()->descriptor($slug) : null;

        return $descriptor !== null && $descriptor->offers(ConnectionMode::Own) ? $descriptor : null;
    }

    private function registry(): ProviderRegistry
    {
        return $this->container->make(ProviderRegistry::class);
    }
}
