<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PlatformMode;
use App\Application\Payment\ProviderDescriptor;
use App\Application\Payment\ProviderRegistry;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Platform mode's one button: the body names a registered provider whose platform mode is
 * offered, its keys set and the fee configured (PlatformMode); any other is the 400, worded
 * by a key of lang/{locale}/admin/payments.php in the request's language.
 */
final class PlatformConnectRequest extends AdminWriteRequest
{
    public const string PROVIDER = 'provider';

    public const string NOT_PLATFORM = 'admin/payments.validation.not_platform';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $platform = $this->container->make(PlatformMode::class);

        return [
            self::PROVIDER => ['required', 'string', Rule::in(array_values(array_filter(
                array_map(fn (ProviderDescriptor $descriptor): string => $descriptor->slug, $this->registry()->descriptors()),
                fn (string $slug): bool => $platform->offered($slug),
            )))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::PROVIDER.'.required' => PaymentCredentialsRequest::line(PaymentCredentialsRequest::NO_PROVIDER),
            self::PROVIDER.'.string' => PaymentCredentialsRequest::line(PaymentCredentialsRequest::NO_PROVIDER),
            self::PROVIDER.'.in' => PaymentCredentialsRequest::line(self::NOT_PLATFORM),
        ];
    }

    public function adapter(): PaymentAdapter
    {
        $slug = $this->input(self::PROVIDER);

        return (is_string($slug) ? $this->registry()->adapter($slug) : null)
            ?? throw new LogicException('The payment provider was validated and the registry does not hold it.');
    }

    private function registry(): ProviderRegistry
    {
        return $this->container->make(ProviderRegistry::class);
    }
}
