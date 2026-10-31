<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DbDriver;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * @return array<string, mixed>
     */
    protected function behind(string $host, int $port = 22, string $username = 'tunnel', bool $enabled = true): array
    {
        return ['ssh' => ['enabled' => $enabled, 'host' => $host, 'port' => $port, 'username' => $username, 'auth' => 'password']];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function createWith(array $options): Project
    {
        return Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function duplicateFor(array $options): ?Project
    {
        return Project::findDuplicate(DbDriver::MySql, '127.0.0.1', 3306, 'app', 'root', $options);
    }

    public function test_profiles_behind_different_bastions_are_not_duplicates(): void
    {
        $this->createWith($this->behind('bastion-a'));

        $this->assertNull($this->duplicateFor($this->behind('bastion-b')));
        $this->assertNull($this->duplicateFor($this->behind('bastion-a', port: 2222)));
        $this->assertNull($this->duplicateFor($this->behind('bastion-a', username: 'other')));
        $this->assertNull($this->duplicateFor([]), 'A direct connection is not the same database as one behind a bastion.');
    }

    public function test_the_same_bastion_is_a_duplicate(): void
    {
        $existing = $this->createWith($this->behind('bastion-a'));

        $this->assertTrue($existing->is($this->duplicateFor($this->behind('bastion-a'))));
    }

    public function test_disabled_ssh_counts_as_a_direct_connection(): void
    {
        // Disabling SSH keeps the bastion settings, but they no longer apply.
        $existing = $this->createWith($this->behind('bastion-a', enabled: false));

        $this->assertTrue($existing->is($this->duplicateFor([])));
        $this->assertNull($this->duplicateFor($this->behind('bastion-a')));
    }

    /**
     * The unique index must agree with sshIdentity(): what findDuplicate()
     * lets through, the database must accept, and the other way round.
     */
    public function test_the_unique_index_agrees_with_the_duplicate_check(): void
    {
        $this->createWith($this->behind('bastion-a'));
        $this->createWith($this->behind('bastion-b'));
        $this->createWith($this->behind('bastion-a', port: 2222));
        $this->createWith($this->behind('bastion-a', username: 'other'));
        $this->createWith([]);

        // The five above, plus the PostgreSQL profile from setUp().
        $this->assertSame(6, Project::count());

        foreach ([$this->behind('bastion-a'), [], $this->behind('bastion-a', enabled: false)] as $options) {
            try {
                // A savepoint, so PostgreSQL can carry on after the rejected insert.
                DB::transaction(fn () => $this->createWith($options));
                $this->fail('Expected the unique index to reject '.json_encode($options));
            } catch (UniqueConstraintViolationException) {
                $this->assertNotNull($this->duplicateFor($options));
            }
        }
    }
}
