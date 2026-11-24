<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Fenced code blocks (```sql … ```) in an AI reply, for the code panel beside
 * the chat. Works on the Markdown source, so it sees exactly what the model
 * wrote; the code is returned as plain text and escaped wherever it is shown.
 */
final class CodeBlocks
{
    /**
     * An opening fence of three or more backticks or tildes, an optional
     * language, the code, and a closing fence of the same character that is
     * at least as long, as CommonMark defines it.
     */
    private const PATTERN = '/^ {0,3}(?<fence>`{3,}|~{3,})[ \t]*(?<info>[^\n`]*)\n(?<code>.*?)^ {0,3}\k<fence>[`~]*[ \t]*$/ms';

    /**
     * @return list<array{language: string, code: string}>
     */
    public static function extract(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);

        preg_match_all(self::PATTERN, $markdown, $matches, PREG_SET_ORDER);

        $blocks = [];

        foreach ($matches as $match) {
            $code = rtrim($match['code'], "\n");

            if (trim($code) === '') {
                continue;
            }

            $language = strtolower(strtok(trim($match['info']), " \t") ?: '');

            $blocks[] = [
                'language' => preg_match('/^[a-z0-9+#.-]{1,20}$/', $language) === 1 ? $language : 'text',
                'code' => $code,
            ];
        }

        return $blocks;
    }
}
