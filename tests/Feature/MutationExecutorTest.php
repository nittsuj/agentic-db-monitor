<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActionCategory;
use App\Models\PendingAction;
use App\Services\Database\MutationExecutor;
use App\Services\Database\StatementClassifier;
use App\Services\Database\TargetConnectionManager;
use Tests\TestCase;

class MutationExecutorTest extends TestCase
{
    public function test_refuses_multiple_statements_before_connecting(): void
    {
        $connections = \Mockery::mock(TargetConnectionManager::class);
        $connections->shouldNotReceive('connect');

        $executor = new MutationExecutor($connections, new StatementClassifier);

        $action = new PendingAction([
            'command' => 'UPDATE users SET role = \'admin\' WHERE id = 1; DROP TABLE users;',
            'category' => ActionCategory::Dml,
        ]);

        $result = $executor->execute($action);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('more than one statement', $result['error']);
    }
}
