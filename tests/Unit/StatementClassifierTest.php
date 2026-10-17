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
}
