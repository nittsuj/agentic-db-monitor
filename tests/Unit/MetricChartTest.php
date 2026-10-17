<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\Components\Dashboard\MetricChart;
use PHPUnit\Framework\TestCase;

class MetricChartTest extends TestCase
{
    public function test_highest_value_maps_to_y_zero(): void
    {
        $chart = new MetricChart(collect([0.0, 50.0, 100.0]));

        $this->assertEquals(0.0, $chart->y(100.0));
    }

    public function test_zero_maps_to_y_height(): void
    {
        $chart = new MetricChart(collect([0.0, 50.0, 100.0]));

        $this->assertEquals(MetricChart::HEIGHT, $chart->y(0.0));
    }

    public function test_single_value_series_does_not_divide_by_zero(): void
    {
        $chart = new MetricChart(collect([42.0]));

        $this->assertEquals(0.0, $chart->y(42.0));
        $this->assertNotEmpty($chart->points());
        $this->assertSame('0,0', $chart->points());
    }

    public function test_limit_is_included_in_scale_bounds(): void
    {
        // Values only go up to 50, but threshold limit is 100
        $chart = new MetricChart(collect([10.0, 20.0, 50.0]), limit: 100.0);

        // Limit is highest, so y(100) must be 0
        $this->assertEquals(0.0, $chart->y(100.0));

        // 50 is halfway between 0 and 100, so y(50) should be half height
        $this->assertEquals(MetricChart::HEIGHT / 2, $chart->y(50.0));
    }

    public function test_points_calculates_horizontal_step_across_width(): void
    {
        $chart = new MetricChart(collect([0.0, 100.0]));

        // Width is 600, count is 2 -> step is 600
        // Point 0: 0,140
        // Point 1: 600,0
        $this->assertSame('0,140 600,0', $chart->points());
    }
}
