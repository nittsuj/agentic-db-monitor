<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\CodeBlocks;
use PHPUnit\Framework\TestCase;

class CodeBlocksTest extends TestCase
{
    public function test_extracts_each_fenced_block_with_its_language_in_order(): void
    {
        $reply = "Try this index:\n\n```sql\nCREATE INDEX idx ON orders (created_at);\n```\n\nThen check it:\n\n```SQL\nEXPLAIN SELECT 1;\n```\n";

        $this->assertSame([
            ['language' => 'sql', 'code' => 'CREATE INDEX idx ON orders (created_at);'],
            ['language' => 'sql', 'code' => 'EXPLAIN SELECT 1;'],
        ], CodeBlocks::extract($reply));
    }

    public function test_a_block_without_a_language_is_plain_text(): void
    {
        $this->assertSame([['language' => 'text', 'code' => 'ls -la']], CodeBlocks::extract("```\nls -la\n```"));
    }

    public function test_keeps_the_code_exactly_including_html_and_inner_backticks(): void
    {
        $code = "<script>alert(1)</script>\n<p>`inline` and ``` inside</p>";

        $this->assertSame([['language' => 'html', 'code' => $code]], CodeBlocks::extract("````html\n{$code}\n````"));
    }

    public function test_tilde_fences_and_windows_line_endings_are_understood(): void
    {
        $this->assertSame([['language' => 'json', 'code' => '{"a": 1}']], CodeBlocks::extract("~~~json\r\n{\"a\": 1}\r\n~~~\r\n"));
    }

    public function test_only_the_first_word_of_the_info_string_is_the_language(): void
    {
        $this->assertSame('php', CodeBlocks::extract("```php title=\"x.php\"\necho 1;\n```")[0]['language']);
    }

    public function test_an_odd_language_name_becomes_plain_text(): void
    {
        $this->assertSame('text', CodeBlocks::extract("```\"><img>\nx\n```")[0]['language']);
    }

    public function test_empty_blocks_unclosed_fences_and_inline_code_are_ignored(): void
    {
        $this->assertSame([], CodeBlocks::extract("Use `SELECT 1` here.\n\n```sql\n\n```\n\n```sql\nSELECT unfinished"));
    }
}
