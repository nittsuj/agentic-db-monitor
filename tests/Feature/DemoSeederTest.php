<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\MetricSample;
use App\Models\Project;
use App\Models\Threshold;
use App\Models\User;
use App\Services\Ai\AiProviderConfig;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_demo_workspace(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(2, Project::count());
        $this->assertSame(384, MetricSample::count());
        $this->assertSame(6, Threshold::count());
    }

    public function test_the_seeded_project_password_is_encrypted_with_the_app_key(): void
    {
        $this->seed(DemoSeeder::class);

        $project = Project::where('name', 'Local MySQL Playground')->firstOrFail();
        $raw = \DB::table('projects')->where('id', $project->id)->value('password');

        $this->assertNotSame('secret', $raw);
        $this->assertSame('secret', Crypt::decryptString((string) $raw));
        $this->assertSame('secret', $project->password);
    }

    public function test_the_seeded_provider_points_at_the_its_llm_gateway_without_a_placeholder_key(): void
    {
        $this->seed(DemoSeeder::class);

        $setting = AiSetting::where('is_active', true)->firstOrFail();

        $this->assertSame('openai_compatible', $setting->provider);
        $this->assertSame('qwen3:14b', $setting->model);
        $this->assertSame('https://senopati.its.ac.id/llm-gateway/v1', $setting->base_url);
        $this->assertNull($setting->api_key);
        $this->assertSame('', AiProviderConfig::apiKey($setting));
    }

    public function test_seeding_twice_does_not_duplicate_rows(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(2, Project::count());
        $this->assertSame(384, MetricSample::count());
        $this->assertSame(6, Threshold::count());
        $this->assertSame(1, AiSetting::count());
    }
}
