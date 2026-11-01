<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsAgent;
use Database\Factories\MutationPlannerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MutationPlanner extends Model
{
    /** @use HasFactory<MutationPlannerFactory> */
    use HasFactory;

    use IsAgent;

    /** Slug of this agent's own row, inserted by its migration. */
    public const SLUG = 'mutation-planner';
}
