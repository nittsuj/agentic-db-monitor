<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DbDriver;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFindDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected Project $existing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->existing = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'app',
            'username' => 'monitor',
        ]);
    }

    public function test_finds_a_profile_with_the_same_connection_details(): void
    {
        $duplicate = Project::findDuplicate(DbDriver::PgSql, 'db.internal', 5432, 'app', 'monitor');

        $this->assertTrue($this->existing->is($duplicate));
    }

    public function test_any_differing_field_is_not_a_duplicate(): void
    {
        $this->assertNull(Project::findDuplicate(DbDriver::MySql, 'db.internal', 5432, 'app', 'monitor'));
        $this->assertNull(Project::findDuplicate(DbDriver::PgSql, 'other.internal', 5432, 'app', 'monitor'));
        $this->assertNull(Project::findDuplicate(DbDriver::PgSql, 'db.internal', 6432, 'app', 'monitor'));
        $this->assertNull(Project::findDuplicate(DbDriver::PgSql, 'db.internal', 5432, 'other', 'monitor'));
        $this->assertNull(Project::findDuplicate(DbDriver::PgSql, 'db.internal', 5432, 'app', 'other'));
    }

    public function test_the_profile_being_edited_is_not_its_own_duplicate(): void
    {
        $this->assertNull(Project::findDuplicate(DbDriver::PgSql, 'db.internal', 5432, 'app', 'monitor', ignoreId: $this->existing->id));
    }
}
