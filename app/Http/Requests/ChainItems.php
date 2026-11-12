<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\SegmentChoice;
use App\Domain\Chain\Chain;
use App\Http\ApiLines;
use Closure;
use LogicException;

/**
 * The chain a customer describes, validated the same way wherever it arrives: one to
 * max_chain_length items, each an object with a service_id and a staff_id key that is present
 * and either null or a positive integer, since the booking page sends the null explicitly and an
 * absent pick and no pick must be one thing.
 *
 * Both routes that take a chain share this, because the availability query and the hold answer
 * the same question about the same list and a customer who can ask must be able to book. The
 * two differ in where the list arrives and what else is beside it, not in what an item is.
 *
 * The sentences are keys of api.php under validation, read in the request's language, the cap
 * filled from Chain::MAX_SEGMENTS. An item is named by its number, :n counted from one, and
 * never by an ordinal, so one whole sentence per refusal reads in either language and no suffix
 * is spliced onto a number. The sentences are spelled out per position, the number filled by the
 * translator rather than by the framework's position placeholder, so a sentence is filled
 * through the one helper; the cap is five, so the list is short.
 */
final readonly class ChainItems
{
    /**
     * The rules, under the keys a form request nests them at.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'max:'.Chain::MAX_SEGMENTS],
            'items.*' => ['array'],
            'items.*.service_id' => ['required', self::positiveInteger('api.validation.item.service_id_integer')],
            'items.*.staff_id' => ['present', 'nullable', self::positiveInteger('api.validation.item.staff_id_integer')],
        ];
    }

    /**
     * The sentences, one per refusal, each naming the field or the item by its number.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [
            'items.required' => ApiLines::line('api.validation.items.required'),
            'items.array' => ApiLines::line('api.validation.items.list'),
            'items.list' => ApiLines::line('api.validation.items.list'),
            'items.max' => ApiLines::choice('api.validation.items.max', Chain::MAX_SEGMENTS),
        ];

        for ($index = 0; $index < Chain::MAX_SEGMENTS; $index++) {
            $messages[sprintf('items.%d.array', $index)] = self::aboutItem('api.validation.item.object', $index);
            $messages[sprintf('items.%d.service_id.required', $index)] = self::aboutItem('api.validation.item.no_service_id', $index);
            $messages[sprintf('items.%d.staff_id.present', $index)] = self::aboutItem('api.validation.item.no_staff_id', $index);
        }

        return $messages;
    }

    /**
     * A sentence of api.php about the item at a zero-based index, the item named by its number
     * counted from one, its :n: the chain's own sentences and the availability query's two about
     * the catalog.
     */
    public static function aboutItem(string $key, int $index): string
    {
        return ApiLines::line($key, ['n' => (string) ($index + 1)]);
    }

    /**
     * The items as the rules left them, narrowed to what the rules guarantee.
     *
     * @return list<array{service_id: int, staff_id: int|null}>
     */
    public static function pairs(mixed $items): array
    {
        if (! is_array($items)) {
            throw new LogicException('The items were validated and are a list.');
        }

        $narrowed = [];

        foreach ($items as $item) {
            $serviceId = is_array($item) ? ($item['service_id'] ?? null) : null;
            $staffId = is_array($item) ? ($item['staff_id'] ?? null) : null;

            if (! is_int($serviceId) || ($staffId !== null && ! is_int($staffId))) {
                throw new LogicException('The items were validated and carry integer ids.');
            }

            $narrowed[] = ['service_id' => $serviceId, 'staff_id' => $staffId];
        }

        return $narrowed;
    }

    /**
     * The validated items as the use cases take them, in order.
     *
     * @return list<SegmentChoice>
     */
    public static function choices(mixed $items): array
    {
        return array_map(fn (array $item): SegmentChoice => new SegmentChoice($item['service_id'], $item['staff_id']), self::pairs($items));
    }

    /**
     * The zero-based index of the item an attribute like items.2.staff_id belongs to.
     */
    private static function indexOf(string $attribute): int
    {
        $segments = explode('.', $attribute);

        return (int) ($segments[1] ?? 0);
    }

    /**
     * An integer at or above one, the JSON kind and not a numeric string, since the items are
     * JSON; null besides where the field allows it, which the nullable rule has already let
     * through before this runs. Refused with the field's own sentence about the item, the
     * staff_id's saying null is allowed.
     */
    private static function positiveInteger(string $key): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($key): void {
            if (! is_int($value) || $value < 1) {
                $fail(self::aboutItem($key, self::indexOf($attribute)));
            }
        };
    }
}
