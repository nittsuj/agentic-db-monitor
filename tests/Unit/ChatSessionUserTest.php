<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSessionUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_many_chat_sessions_and_each_belongs_to_the_user(): void
    {
        $user = User::factory()->create();
        $sessions = ChatSession::factory()->count(2)->for($user)->create();
        ChatSession::factory()->create();

        $this->assertEqualsCanonicalizing($sessions->pluck('id')->all(), $user->chatSessions->pluck('id')->all());
        $this->assertTrue($sessions->first()->user->is($user));
    }

    public function test_deleting_a_user_deletes_their_sessions_and_messages(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::factory()->for($user)->create();
        ChatMessage::factory()->count(3)->for($session)->create();

        $other = ChatSession::factory()->create();
        ChatMessage::factory()->for($other)->create();

        $user->delete();

        $this->assertModelMissing($session);
        $this->assertSame(0, ChatMessage::where('chat_session_id', $session->id)->count());
        $this->assertModelExists($other);
        $this->assertSame(1, ChatMessage::where('chat_session_id', $other->id)->count());
    }

    public function test_sessions_from_before_users_existed_may_have_no_user(): void
    {
        $session = ChatSession::create(['project_id' => Project::factory()->create()->id, 'title' => 'Old chat']);

        $this->assertNull($session->fresh()->user);
    }

    public function test_the_demo_seeder_gives_its_sessions_to_the_demo_user(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertSame(2, $admin->chatSessions()->count());

        // A demo session from before this change has no user; reseeding fills it in.
        ChatSession::query()->update(['user_id' => null]);
        $this->seed(DemoSeeder::class);

        $this->assertSame(0, ChatSession::whereNull('user_id')->count());
        $this->assertSame(2, $admin->chatSessions()->count());
    }
}
