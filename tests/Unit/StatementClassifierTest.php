<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActionCategory;
use App\Services\Database\StatementClassifier;
use PHPUnit\Framework\TestCase;

class StatementClassifierTest extends TestCase
{
    public function test_classifies_ddl(): void
    {
        $c = new StatementClassifier;
        $this->assertSame(ActionCategory::Ddl, $c->classify('CREATE INDEX idx ON t (a)'));
        $this->assertSame(ActionCategory::Ddl, $c->classify('DROP TABLE t'));
    }

    public function test_classifies_maintenance(): void
    {
        $c = new StatementClassifier;
        $this->assertSame(ActionCategory::Maintenance, $c->classify('VACUUM ANALYZE t'));
        $this->assertSame(ActionCategory::Maintenance, $c->classify('OPTIMIZE TABLE t'));
    }

    public function test_classifies_dml(): void
    {
        $c = new StatementClassifier;
        $this->assertSame(ActionCategory::Dml, $c->classify('DELETE FROM t WHERE id = 1'));
        $this->assertSame(ActionCategory::Dml, $c->classify('UPDATE t SET a = 1'));
    }

    public function test_read_only_returns_null(): void
    {
        $c = new StatementClassifier;
        $this->assertNull($c->classify('SELECT * FROM t'));
        $this->assertFalse($c->isMutating('SELECT 1'));
    }

    public function test_single_statement_with_a_trailing_semicolon_is_not_multiple(): void
    {
        $c = new StatementClassifier;

        $this->assertFalse($c->containsMultipleStatements('UPDATE t SET a = 1;'));
        $this->assertFalse($c->containsMultipleStatements("SELECT 1;\n\n"));
        $this->assertFalse($c->containsMultipleStatements('SELECT 1; -- trailing comment'));
    }

    public function test_detects_multiple_statements(): void
    {
        $c = new StatementClassifier;

        $this->assertTrue($c->containsMultipleStatements('UPDATE t SET a = 1; DROP TABLE t'));
        $this->assertTrue($c->containsMultipleStatements('SELECT 1; DELETE FROM t'));
        $this->assertTrue($c->containsMultipleStatements("DROP TABLE a;\nDROP TABLE b;"));
    }

    public function test_ignores_semicolons_inside_strings_and_identifiers(): void
    {
        $c = new StatementClassifier;

        $this->assertFalse($c->containsMultipleStatements("UPDATE t SET note = 'a;b'"));
        $this->assertFalse($c->containsMultipleStatements('UPDATE "weird;table" SET a = 1'));
        $this->assertFalse($c->containsMultipleStatements('UPDATE `weird;table` SET a = 1'));
        $this->assertFalse($c->containsMultipleStatements("UPDATE t SET note = 'it''s; fine'"));
    }

    public function test_ignores_semicolons_inside_comments(): void
    {
        $c = new StatementClassifier;

        $this->assertFalse($c->containsMultipleStatements("UPDATE t SET a = 1 -- ; DROP TABLE t\n"));
        $this->assertFalse($c->containsMultipleStatements('UPDATE t SET a = 1 /* ; DROP TABLE t */'));
        $this->assertFalse($c->containsMultipleStatements("UPDATE t SET a = 1 # ; DROP TABLE t\n"));
    }

    public function test_ignores_semicolons_inside_postgres_dollar_quotes(): void
    {
        $c = new StatementClassifier;

        $this->assertFalse($c->containsMultipleStatements('SELECT $$a;b$$'));
        $this->assertFalse($c->containsMultipleStatements('SELECT $body$a;b$body$'));
    }

    public function test_classifies_a_mutating_statement_that_is_not_first(): void
    {
        $c = new StatementClassifier;

        $this->assertSame(ActionCategory::Ddl, $c->classify('SELECT 1; DROP TABLE t'));
        $this->assertTrue($c->isMutating('SELECT 1; DROP TABLE t'));
    }

    public function test_leading_comments_do_not_hide_the_statement_type(): void
    {
        $c = new StatementClassifier;

        $this->assertSame(ActionCategory::Ddl, $c->classify("-- maintenance\nDROP TABLE t"));
    }
}
