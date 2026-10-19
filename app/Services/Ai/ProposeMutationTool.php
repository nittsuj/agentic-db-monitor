<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Models\Project;
use App\Services\Database\StatementClassifier;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

class ProposeMutationTool
{
    public static function make(
        Project $project,
        string $chatSessionId,
        ?StatementClassifier $classifier = null,
    ): Tool {
        $classifier ??= app(StatementClassifier::class);

        return (new Tool)
            ->as('propose_mutation')
            ->for('Propose a mutating SQL statement (INSERT/UPDATE/DELETE/DDL/maintenance) for explicit user approval. The statement is NOT executed; it is queued for approval. Only use for statements the user has requested.')
            ->withParameter(new StringSchema('command', 'The exact SQL statement to propose'))
            ->using(function (string $command) use ($project, $chatSessionId, $classifier): string {
                if ($classifier->containsMultipleStatements($command)) {
                    return self::respond([
                        'status' => 'rejected',
                        'reason' => 'Multiple statements are not allowed. Propose exactly one statement per approval request.',
                    ]);
                }

                $category = $classifier->classify($command);

                if ($category === null) {
                    return self::respond([
                        'status' => 'rejected',
                        'reason' => 'Statement is read-only or not recognized; use diagnostic tools instead.',
                    ]);
                }

                $action = PendingAction::create([
                    'project_id' => $project->id,
                    'chat_session_id' => $chatSessionId,
                    'engine' => $project->driver->value,
                    'category' => $category,
                    'command' => $command,
                    'status' => ActionStatus::Pending,
                    'proposed_at' => now(),
                ]);

                return self::respond([
                    'status' => 'pending_approval',
                    'pending_action_id' => $action->id,
                    'category' => $category->value,
                    'message' => 'Action proposed and awaiting user approval. Do not execute or retry; inform the user.',
                ]);
            });
    }

    /**
     * Prism tools must return text; JSON keeps the structured shape the model needs.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function respond(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
