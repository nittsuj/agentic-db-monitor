<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DockerEntrypointTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = (string) file_get_contents(base_path('docker-entrypoint.sh'));
    }

    public function test_the_persisted_key_wins_over_the_environment_variable(): void
    {
        $persisted = strpos($this->script, 'if [ -f /data/app.key ]; then');
        $fallback = strpos($this->script, 'elif [ -n "$APP_KEY" ]; then');

        $this->assertIsInt($persisted);
        $this->assertIsInt($fallback);
        $this->assertLessThan($fallback, $persisted, 'The persisted key must be read before the environment fallback.');
    }

    public function test_the_persisted_key_is_used_and_exported(): void
    {
        $this->assertStringContainsString('APP_KEY="$(cat /data/app.key)"', $this->script);
        $this->assertStringContainsString('export APP_KEY', $this->script);
    }

    public function test_a_key_is_persisted_on_the_first_run(): void
    {
        $this->assertStringContainsString('printf \'%s\' "$APP_KEY" > /data/app.key', $this->script);
        $this->assertStringContainsString('php artisan key:generate --show > /data/app.key', $this->script);
    }

    public function test_the_container_migrates_seeds_and_starts_the_scheduler(): void
    {
        $this->assertStringContainsString('php artisan migrate --force', $this->script);
        $this->assertStringContainsString('php artisan db:seed --force', $this->script);
        $this->assertStringContainsString('php artisan schedule:work &', $this->script);
        $this->assertStringContainsString('exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload', $this->script);
    }
}
