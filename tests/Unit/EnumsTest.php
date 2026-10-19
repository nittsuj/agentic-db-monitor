<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActionCategory;
use App\Enums\ActionDecision;
use App\Enums\ActionStatus;
use App\Enums\ChatRole;
use App\Enums\DbDriver;
use App\Enums\MetricKey;
use App\Enums\ThresholdOperator;
use App\Enums\ToolSupport;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_db_driver_cases_and_default_ports(): void
    {
        $this->assertSame(['mysql', 'pgsql'], array_map(fn ($c) => $c->value, DbDriver::cases()));
        $this->assertSame(3306, DbDriver::MySql->defaultPort());
        $this->assertSame(5432, DbDriver::PgSql->defaultPort());
    }

    public function test_chat_role_cases(): void
    {
        $this->assertSame(['user', 'assistant', 'system', 'tool'], array_map(fn ($c) => $c->value, ChatRole::cases()));
    }

    public function test_action_category_cases(): void
    {
        $this->assertSame(['maintenance', 'ddl', 'dml'], array_map(fn ($c) => $c->value, ActionCategory::cases()));
    }

    public function test_action_status_cases(): void
    {
        $this->assertSame(['pending', 'approved', 'denied', 'executed', 'failed', 'expired'], array_map(fn ($c) => $c->value, ActionStatus::cases()));
    }

    public function test_action_decision_cases(): void
    {
        $this->assertSame(['approved', 'denied', 'executed', 'failed'], array_map(fn ($c) => $c->value, ActionDecision::cases()));
    }

    public function test_threshold_operator_cases(): void
    {
        $this->assertSame(['gt', 'gte', 'lt', 'lte', 'eq', 'ne'], array_map(fn ($c) => $c->value, ThresholdOperator::cases()));
    }

    public function test_tool_support_cases(): void
    {
        $this->assertSame(['supported', 'unsupported', 'unknown'], array_map(fn ($c) => $c->value, ToolSupport::cases()));

        $this->assertTrue(ToolSupport::Supported->allowsTools());
        $this->assertFalse(ToolSupport::Unsupported->allowsTools());
        $this->assertTrue(ToolSupport::Unknown->allowsTools());
    }

    public function test_metric_key_units_and_direction(): void
    {
        $this->assertCount(11, MetricKey::cases());

        $this->assertSame('%', MetricKey::ConnectionPoolUsed->unit());
        $this->assertSame('count', MetricKey::Locks->unit());
        $this->assertSame('load', MetricKey::Load1m->unit());

        $this->assertTrue(MetricKey::Locks->higherIsWorse());
        $this->assertFalse(MetricKey::BufferHitRatio->higherIsWorse());

        $this->assertTrue(MetricKey::LongRunningQueries->availableFor(DbDriver::PgSql));
        $this->assertFalse(MetricKey::LongRunningQueries->availableFor(DbDriver::MySql));
        $this->assertTrue(MetricKey::Locks->availableFor(DbDriver::MySql));
    }
}
