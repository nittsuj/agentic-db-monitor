<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\ActionCategory;

class StatementClassifier
{
    private const DDL_PATTERNS = [
        '/^\s*(create|alter|drop|truncate|rename)\s+/i',
    ];

    private const MAINTENANCE_PATTERNS = [
        '/^\s*(vacuum|analyze|optimize|repair|reindex)\s+/i',
    ];

    private const MUTATING_PATTERNS = [
        '/^\s*(insert|update|delete|replace|merge|grant|revoke)\s+/i',
    ];

    /**
     * Split SQL into statements on semicolons that are not inside strings,
     * quoted identifiers, comments, or PostgreSQL dollar-quoted blocks.
     *
     * @return array<int, string>
     */
    public function statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $hasCode = false;
        $length = strlen($sql);
        $index = 0;
        $tag = null;

        while ($index < $length) {
            $char = $sql[$index];

            if (($char === '-' && ($sql[$index + 1] ?? '') === '-') || $char === '#') {
                $end = strpos($sql, "\n", $index);
                $end = $end === false ? $length : $end;
                $buffer .= substr($sql, $index, $end - $index);
                $index = $end;

                continue;
            }

            if ($char === '/' && ($sql[$index + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                $buffer .= substr($sql, $index, $end - $index);
                $index = $end;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $this->skipQuoted($sql, $index, $char);
                $buffer .= substr($sql, $index, $end - $index);
                $hasCode = true;
                $index = $end;

                continue;
            }

            if ($char === '$' && $this->matchDollarQuote($sql, $index, $tag)) {
                $closing = strpos($sql, $tag, $index + strlen($tag));
                $end = $closing === false ? $length : $closing + strlen($tag);
                $buffer .= substr($sql, $index, $end - $index);
                $hasCode = true;
                $index = $end;

                continue;
            }

            if ($char === ';') {
                if ($hasCode) {
                    $statements[] = trim($buffer);
                }

                $buffer = '';
                $hasCode = false;
                $index++;

                continue;
            }

            if (! ctype_space($char)) {
                $hasCode = true;
            }

            $buffer .= $char;
            $index++;
        }

        if ($hasCode) {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    public function containsMultipleStatements(string $sql): bool
    {
        return count($this->statements($sql)) > 1;
    }

    public function classify(string $sql): ?ActionCategory
    {
        foreach ($this->statements($sql) as $statement) {
            $category = $this->classifySingle($statement);

            if ($category !== null) {
                return $category;
            }
        }

        return null;
    }

    public function isMutating(string $sql): bool
    {
        return $this->classify($sql) !== null;
    }

    private function classifySingle(string $statement): ?ActionCategory
    {
        $statement = $this->stripLeadingComments($statement);

        foreach (self::DDL_PATTERNS as $pattern) {
            if (preg_match($pattern, $statement)) {
                return ActionCategory::Ddl;
            }
        }

        foreach (self::MAINTENANCE_PATTERNS as $pattern) {
            if (preg_match($pattern, $statement)) {
                return ActionCategory::Maintenance;
            }
        }

        foreach (self::MUTATING_PATTERNS as $pattern) {
            if (preg_match($pattern, $statement)) {
                return ActionCategory::Dml;
            }
        }

        return null;
    }

    /**
     * Return the position just after the closing quote (or the end of the SQL).
     */
    private function skipQuoted(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $index = $start + 1;

        while ($index < $length) {
            if ($sql[$index] === '\\' && $quote !== '`') {
                $index += 2;

                continue;
            }

            if ($sql[$index] === $quote) {
                if (($sql[$index + 1] ?? '') === $quote) {
                    $index += 2;

                    continue;
                }

                return $index + 1;
            }

            $index++;
        }

        return $length;
    }

    private function matchDollarQuote(string $sql, int $offset, ?string &$tag): bool
    {
        if (! preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/', $sql, $matches, 0, $offset)) {
            return false;
        }

        $tag = $matches[0];

        return true;
    }

    private function stripLeadingComments(string $statement): string
    {
        return (string) preg_replace(
            '/\A(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*.*?\*\/)*/s',
            '',
            $statement
        );
    }
}
