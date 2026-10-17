<?php

declare(strict_types=1);

namespace App\View\Components\Dashboard;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class MetricChart extends Component
{
    public const WIDTH = 600;

    public const HEIGHT = 140;

    public int $width = self::WIDTH;

    public int $height = self::HEIGHT;

    private float $min;

    private float $span;

    public function __construct(public Collection $values, public ?float $limit = null)
    {
        $all = $values->when($limit !== null, fn (Collection $v) => $v->concat([$limit]));

        $this->min = (float) min(0, $all->min() ?? 0);
        $this->span = ((float) ($all->max() ?? 0) - $this->min) ?: 1.0;
    }

    public function y(float $value): float
    {
        return round(self::HEIGHT - (($value - $this->min) / $this->span) * self::HEIGHT, 1);
    }

    public function points(): string
    {
        $step = self::WIDTH / max($this->values->count() - 1, 1);

        return $this->values->values()
            ->map(fn (float $value, int $i): string => round($i * $step, 1).','.$this->y($value))
            ->implode(' ');
    }

    public function render(): View
    {
        return view('components.dashboard.metric-chart');
    }
}
