<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Removes <think>...</think> reasoning blocks from a streamed token feed,
 * including tags that are split across chunk boundaries.
 */
final class HiddenTagStreamFilter
{
    private const OPEN_TAG = '<think>';

    private const CLOSE_TAG = '</think>';

    private string $buffer = '';

    private bool $insideTag = false;

    public function push(string $chunk): string
    {
        $this->buffer .= $chunk;
        $visible = '';

        while (true) {
            if ($this->insideTag) {
                $end = strpos($this->buffer, self::CLOSE_TAG);

                if ($end === false) {
                    return $visible;
                }

                $this->buffer = substr($this->buffer, $end + strlen(self::CLOSE_TAG));
                $this->insideTag = false;

                continue;
            }

            $start = strpos($this->buffer, self::OPEN_TAG);

            if ($start === false) {
                $safe = $this->withoutPartialOpenTag($this->buffer);
                $visible .= $safe;
                $this->buffer = substr($this->buffer, strlen($safe));

                return $visible;
            }

            $visible .= substr($this->buffer, 0, $start);
            $this->buffer = substr($this->buffer, $start + strlen(self::OPEN_TAG));
            $this->insideTag = true;
        }
    }

    /**
     * Flush text held back for tag detection. An unterminated think block is
     * discarded rather than shown as model output.
     */
    public function flush(): string
    {
        if ($this->insideTag) {
            $this->buffer = '';
            $this->insideTag = false;

            return '';
        }

        $visible = $this->buffer;
        $this->buffer = '';

        return $visible;
    }

    /**
     * Hold back a suffix that could be the beginning of an opening tag.
     */
    private function withoutPartialOpenTag(string $text): string
    {
        $max = min(strlen($text), strlen(self::OPEN_TAG) - 1);

        for ($length = $max; $length > 0; $length--) {
            if (substr($text, -$length) === substr(self::OPEN_TAG, 0, $length)) {
                return substr($text, 0, strlen($text) - $length);
            }
        }

        return $text;
    }
}
