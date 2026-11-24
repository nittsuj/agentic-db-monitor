<?php

declare(strict_types=1);

namespace App\Services\Eval;

/**
 * One question for the assistant and how to tell a correct answer from a wrong one.
 * The expected values come from the target database itself (see EvalCaseFactory).
 */
final readonly class EvalCase
{
    /**
     * @param  list<string>  $mustMention  every one of these must appear in the answer (whole word, any case)
     * @param  list<string>  $mustMentionAny  at least one of these phrases must appear, when not empty
     * @param  string|null  $mustCallTool  the answer only counts when this tool was called
     */
    public function __construct(
        public string $id,
        public string $category,
        public string $question,
        public array $mustMention = [],
        public array $mustMentionAny = [],
        public ?string $mustCallTool = null,
    ) {}

    /**
     * @param  list<string>  $toolsCalled
     * @return array{passed: bool, reason: string}
     */
    public function grade(string $answer, array $toolsCalled): array
    {
        $missing = array_values(array_filter(
            $this->mustMention,
            fn (string $term): bool => ! self::mentions($answer, $term),
        ));

        if ($missing !== []) {
            return ['passed' => false, 'reason' => 'missing: '.implode(', ', $missing)];
        }

        if ($this->mustMentionAny !== []
            && ! array_filter($this->mustMentionAny, fn (string $phrase): bool => self::mentions($answer, $phrase))) {
            return ['passed' => false, 'reason' => 'expected one of: '.implode(' | ', $this->mustMentionAny)];
        }

        if ($this->mustCallTool !== null && ! in_array($this->mustCallTool, $toolsCalled, true)) {
            return ['passed' => false, 'reason' => "did not call {$this->mustCallTool}"];
        }

        return ['passed' => true, 'reason' => ''];
    }

    /**
     * Whole-word, case-insensitive match, so "id" does not match inside "sku_id"
     * and "8" does not match inside "8,205". Typographic apostrophes count as
     * straight ones, since models often write "isn’t" for "isn't".
     */
    public static function mentions(string $answer, string $term): bool
    {
        $answer = str_replace(["\u{2019}", "\u{2018}"], "'", $answer);
        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($term, '/').'(?![\p{L}\p{N}_]|,\d)/iu';

        return preg_match($pattern, $answer) === 1;
    }
}
