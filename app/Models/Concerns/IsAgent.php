<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared by the agent models a tool execution log can point to. Each agent
 * table holds the agent's own row (slug = static::SLUG) and the names of the
 * chat tools it handles.
 */
trait IsAgent
{
    public function initializeIsAgent(): void
    {
        $this->mergeFillable(['slug', 'name', 'description', 'handles']);
        $this->mergeCasts(['handles' => 'array']);
    }

    /**
     * Agents whose handles list contains the tool.
     *
     * @param  Builder<static>  $query
     */
    public function scopeHandling(Builder $query, string $tool): void
    {
        $query->whereJsonContains('handles', $tool);
    }

    public function handlesTool(string $tool): bool
    {
        return in_array($tool, $this->handles ?? [], true);
    }
}
