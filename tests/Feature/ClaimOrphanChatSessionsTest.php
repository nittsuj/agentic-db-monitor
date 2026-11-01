<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\ClaimOrphanChatSessions;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClaimOrphanChatSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function orphanSession(): ChatSession
    {
        return ChatSession::create(['project_id' => Project::factory()->create()->id, 'title' => 'Old chat']);
    }

    protected function register(string $email): User
    {
        $this->post('/register', [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard'));

        auth()->logout();

        return User::where('email', $email)->sole();
    }

    public function test_the_listener_is_registered_for_the_registered_event(): void
    {
        Event::fake();

        Event::assertListening(Registered::class, ClaimOrphanChatSessions::class);
    }

    public function test_the_first_registered_user_takes_over_sessions_without_a_user(): void
    {
        $old = [$this->orphanSession(), $this->orphanSession()];

        $first = $this->register('first@example.com');

        foreach ($old as $session) {
            $this->assertSame($first->id, $session->fresh()->user_id);
        }
    }

    public function test_a_later_user_takes_over_nothing(): void
    {
        $this->register('first@example.com');
        $later = $this->orphanSession();

        $second = $this->register('second@example.com');

        $this->assertNull($later->fresh()->user_id);
        $this->assertSame(0, $second->chatSessions()->count());
    }

    public function test_registering_after_the_demo_data_is_seeded_takes_over_nothing(): void
    {
        // The demo seeder creates admin@example.com, so a new account is not the first one.
        $this->seed(DemoSeeder::class);
        $old = $this->orphanSession();

        $this->register('owner@example.com');

        $this->assertNull($old->fresh()->user_id);
    }
}
