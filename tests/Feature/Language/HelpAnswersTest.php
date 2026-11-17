<?php

/*
 * The help panel's answers, one file a language: the ten topics in the order the panel lists
 * them, each with a title and its two answers in order, each answer a question and its
 * paragraphs. The Greek file holds the same topics and answers in the same order with as many
 * paragraphs each, so the panel reads the same in both languages, and no title, question or
 * paragraph is empty.
 */

/**
 * The language's file read strictly: a value of the wrong type, or a key beside the ones an entry
 * holds, fails the test where it stands.
 *
 * @return list<array{key: string, title: string, answers: list<array{key: string, question: string, answer: list<string>}>}>
 */
function helpAnswersOf(string $language): array
{
    $topics = [];

    foreach (helpAnswersArray(require lang_path($language.'/admin/help_answers.php')) as $key => $topic) {
        $topic = helpAnswersArray($topic);
        expect(array_keys($topic))->toBe(['title', 'answers']);
        $answers = [];

        foreach (helpAnswersArray($topic['answers'] ?? null) as $answerKey => $answer) {
            $answer = helpAnswersArray($answer);
            $paragraphs = helpAnswersArray($answer['answer'] ?? null);
            expect(array_keys($answer))->toBe(['question', 'answer'])
                ->and($paragraphs)->toBeList();

            $answers[] = [
                'key' => helpAnswersString($answerKey),
                'question' => helpAnswersString($answer['question'] ?? null),
                'answer' => array_map(helpAnswersString(...), array_values($paragraphs)),
            ];
        }

        $topics[] = ['key' => helpAnswersString($key), 'title' => helpAnswersString($topic['title'] ?? null), 'answers' => $answers];
    }

    return $topics;
}

/**
 * @return array<mixed>
 */
function helpAnswersArray(mixed $value): array
{
    expect($value)->toBeArray();

    return is_array($value) ? $value : [];
}

function helpAnswersString(mixed $value): string
{
    expect($value)->toBeString();

    return is_string($value) ? $value : '';
}

/**
 * The file's shape alone: every topic's key with its answers' keys and their paragraph counts.
 *
 * @param  list<array{key: string, title: string, answers: list<array{key: string, question: string, answer: list<string>}>}>  $topics
 * @return list<array{0: string, 1: list<array{0: string, 1: int}>}>
 */
function helpAnswersShape(array $topics): array
{
    return array_map(fn (array $topic): array => [
        $topic['key'],
        array_map(fn (array $answer): array => [$answer['key'], count($answer['answer'])], $topic['answers']),
    ], $topics);
}

it('holds the ten topics in the panel\'s order, two answers each', function (string $language) {
    $topics = helpAnswersOf($language);

    expect(array_column($topics, 'key'))->toBe(['getting_started', 'bookings', 'calendar', 'closures_and_hours', 'staff_and_shifts', 'services', 'booking_page', 'payments', 'billing', 'team']);

    foreach ($topics as $topic) {
        expect($topic['answers'])->toHaveCount(2);
    }
})->with(['en', 'el']);

it('gives every topic a title and every answer a question and one to three paragraphs, none of them empty', function (string $language) {
    foreach (helpAnswersOf($language) as $topic) {
        expect(trim($topic['title']))->not->toBe('');

        foreach ($topic['answers'] as $answer) {
            expect($answer['key'])->toMatch('/^[a-z_]+$/')
                ->and(trim($answer['question']))->not->toBe('')
                ->and(count($answer['answer']))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);

            foreach ($answer['answer'] as $paragraph) {
                expect(trim($paragraph))->not->toBe('');
            }
        }
    }
})->with(['en', 'el']);

it('gives the Greek file the English topics and answers in the same order with as many paragraphs, every line of it Greek', function () {
    $en = helpAnswersOf('en');
    $el = helpAnswersOf('el');

    expect(helpAnswersShape($el))->toBe(helpAnswersShape($en));

    // A line counts as Greek when it holds a letter of the Greek script and is not the English
    // line left in its place.
    foreach ($el as $t => $topic) {
        expect($topic['title'])->toMatch('/\p{Greek}/u')->not->toBe($en[$t]['title']);

        foreach ($topic['answers'] as $a => $answer) {
            expect($answer['question'])->toMatch('/\p{Greek}/u')->not->toBe($en[$t]['answers'][$a]['question']);

            foreach ($answer['answer'] as $p => $paragraph) {
                expect($paragraph)->toMatch('/\p{Greek}/u')->not->toBe($en[$t]['answers'][$a]['answer'][$p]);
            }
        }
    }
});
