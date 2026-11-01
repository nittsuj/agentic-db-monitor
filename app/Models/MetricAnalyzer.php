<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsAgent;
use Database\Factories\MetricAnalyzerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetricAnalyzer extends Model
{
    /** @use HasFactory<MetricAnalyzerFactory> */
    use HasFactory;

    use IsAgent;

    /** Slug of this agent's own row, inserted by its migration. */
    public const SLUG = 'metric-analyzer';
}
