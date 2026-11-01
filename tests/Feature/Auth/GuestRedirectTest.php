<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function appPages(): array
    {
        return [
            'home' => ['/'],
            'dashboard' => ['/dashboard'],
            'chat' => ['/chat'],
            'approvals' => ['/approvals'],
            'thresholds' => ['/thresholds'],
            'ai settings' => ['/settings/ai'],
        ];
    }

    #[DataProvider('appPages')]
    public function test_guests_are_sent_to_the_login_page(string $uri): void
    {
        $this->get($uri)->assertRedirect(route('login'));
    }

    public function test_guests_cannot_switch_the_active_project(): void
    {
        $project = Project::factory()->create();

        $this->get(route('switch-project', ['project' => $project->id]))
            ->assertRedirect(route('login'));

        $this->assertNull(session('active_project_id'));
    }

    public function test_after_logging_in_the_guest_lands_on_the_page_they_asked_for(): void
    {
        $user = User::factory()->create();

        $this->get('/approvals')->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/approvals');
    }

    public function test_auth_pages_stay_open_to_guests(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }
}
