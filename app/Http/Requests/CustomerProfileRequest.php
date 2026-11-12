<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Clock;
use App\Site\SiteLanguage;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The body of PUT /admin/customers/{email}/profile: the Profile card's three fields, sent whole.
 *
 * tags is the field's text, required and possibly empty, read as words between commas: each
 * trimmed of white space at either end, an empty one dropped, and each kept once at its first
 * spelling, words differing in case alone being one. At most MOST_TAGS words of at most
 * TAG_LENGTH characters each; past either, or a value that is no text, the one sentence naming
 * both bounds. birthday is a calendar date, YYYY-MM-DD, from EARLIEST to the tenant's today on the
 * Clock port, or null or empty for none. language is en or el, or null or empty for not recorded.
 *
 * Every refusal is a key of lang/{locale}/admin/customers.php under validation, worded in the
 * request's language.
 */
final class CustomerProfileRequest extends AdminWriteRequest
{
    public const string TAGS = 'admin/customers.validation.tags';

    public const string BIRTHDAY = 'admin/customers.validation.birthday';

    public const string LANGUAGE = 'admin/customers.validation.language';

    /** The most words a profile carries, as customer_profiles_tags_check holds it. */
    public const int MOST_TAGS = 10;

    /** The most characters of one word, as customer_profiles_tags_check holds it. */
    public const int TAG_LENGTH = 24;

    /**
     * The earliest birthday taken, which the refusal names by its year and Birthday's calendar
     * starts at.
     */
    public const string EARLIEST = '1900-01-01';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'nullable', 'string'],
            'birthday' => ['nullable', 'string', 'date_format:Y-m-d'],
            'language' => ['nullable', 'string', Rule::in(array_map(fn (SiteLanguage $language): string => $language->value, SiteLanguage::cases()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tags = self::tagsSentence();
        $birthday = self::birthdaySentence();
        $language = StaffRequest::line(self::LANGUAGE);

        return [
            'tags.present' => $tags, 'tags.string' => $tags,
            'birthday.string' => $birthday, 'birthday.date_format' => $birthday,
            'language.string' => $language, 'language.in' => $language,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $errors = $validator->errors();

            if (! $errors->has('tags')) {
                $tags = self::words($this->input('tags'));

                if (count($tags) > self::MOST_TAGS || array_filter($tags, fn (string $tag): bool => mb_strlen($tag) > self::TAG_LENGTH) !== []) {
                    $errors->add('tags', self::tagsSentence());
                }
            }

            $birthday = $this->input('birthday');

            if (! $errors->has('birthday') && is_string($birthday) && $birthday !== '' && ($birthday < self::EARLIEST || $birthday > $this->today())) {
                $errors->add('birthday', self::birthdaySentence());
            }
        });
    }

    /**
     * The words to store, trimmed, each once, in the order written.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return self::words($this->validated('tags'));
    }

    public function birthday(): ?string
    {
        $birthday = $this->validated('birthday');

        return is_string($birthday) && $birthday !== '' ? $birthday : null;
    }

    public function language(): ?string
    {
        $language = $this->validated('language');

        return is_string($language) && $language !== '' ? $language : null;
    }

    /**
     * The field's text as words: split on commas, each trimmed of white space, the empty ones
     * dropped, each kept once at its first spelling, case aside.
     *
     * @return list<string>
     */
    private static function words(mixed $text): array
    {
        $words = [];

        foreach (explode(',', is_string($text) ? $text : '') as $word) {
            $word = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $word) ?? '';
            $key = mb_strtolower($word);

            if ($word !== '' && ! array_key_exists($key, $words)) {
                $words[$key] = $word;
            }
        }

        return array_values($words);
    }

    /**
     * The tags' refusal, read as a choice by the most tags, :n the most characters of one.
     */
    private static function tagsSentence(): string
    {
        return trans_choice(self::TAGS, self::MOST_TAGS, ['n' => (string) self::TAG_LENGTH]);
    }

    /**
     * The birthday's refusal, naming the earliest birthday's year from EARLIEST.
     */
    private static function birthdaySentence(): string
    {
        return StaffRequest::line(self::BIRTHDAY, ['year' => substr(self::EARLIEST, 0, 4)]);
    }

    /** The tenant's today on the Clock port, YYYY-MM-DD. */
    private function today(): string
    {
        return app(Clock::class)->now()->setTimezone(app(CurrentTenant::class)->get()->zone())->format('Y-m-d');
    }
}
