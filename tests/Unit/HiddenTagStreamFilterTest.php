<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\HiddenTagStreamFilter;
use PHPUnit\Framework\TestCase;

class HiddenTagStreamFilterTest extends TestCase
{
    public function test_passes_plain_text_through(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('Hello', $filter->push('Hello'));
        $this->assertSame(' world', $filter->push(' world'));
        $this->assertSame('', $filter->flush());
    }

    public function test_removes_a_complete_thinking_block(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('Before after', $filter->push('Before <think>secret</think>after'));
        $this->assertSame('', $filter->flush());
    }

    public function test_removes_tags_split_across_chunks(): void
    {
        $filter = new HiddenTagStreamFilter;
        $visible = '';

        foreach (['<thi', 'nk>hid', 'den</thi', 'nk>Vis', 'ible'] as $chunk) {
            $visible .= $filter->push($chunk);
        }

        $visible .= $filter->flush();

        $this->assertSame('Visible', $visible);
    }

    public function test_holds_back_a_partial_opening_tag(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('Hello ', $filter->push('Hello <thi'));
        $this->assertSame('!', $filter->push('nk>secret</think>!'));
    }

    public function test_discards_an_unterminated_thinking_block(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('Start ', $filter->push('Start <think>chain of thought'));
        $this->assertSame('', $filter->push(' keeps going'));
        $this->assertSame('', $filter->flush());
    }

    public function test_flushes_text_held_back_for_tag_detection(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('Hi ', $filter->push('Hi <'));
        $this->assertSame('<', $filter->flush());
    }

    public function test_removes_multiple_blocks(): void
    {
        $filter = new HiddenTagStreamFilter;

        $this->assertSame('AB', $filter->push('A<think>1</think>B<think>2</think>'));
    }
}
