<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsAgent;
use Database\Factories\QueryDiagnosticianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueryDiagnostician extends Model
{
    /** @use HasFactory<QueryDiagnosticianFactory> */
    use HasFactory;

    use IsAgent;

    /** Slug of this agent's own row, inserted by its migration. */
    public const SLUG = 'query-diagnostician';
}
