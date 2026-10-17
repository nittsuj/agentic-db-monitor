<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActionCategory;
use App\Enums\ActionDecision;
use App\Enums\ActionStatus;
use App\Enums\ChatRole;
use App\Enums\DbDriver;
use App\Enums\ThresholdOperator;
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
        $this->assertSame(['pending', 'approved', 'denied', 'executed', 'failed'], array_map(fn ($c) => $c->value, ActionStatus::cases()));
    }

    public function test_action_decision_cases(): void
    {
        $this->assertSame(['approved', 'denied', 'executed', 'failed'], array_map(fn ($c) => $c->value, ActionDecision::cases()));
    }

    public function test_threshold_operator_cases(): void
    {
        $this->assertSame(['gt', 'gte', 'lt', 'lte', 'eq', 'ne'], array_map(fn ($c) => $c->value, ThresholdOperator::cases()));
    }
}
