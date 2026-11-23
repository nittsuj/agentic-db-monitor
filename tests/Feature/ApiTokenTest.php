<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_issue_a_chat_token(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'curl',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame(['chat:ai'], $token->abilities);
        $this->assertSame('curl', $token->name);
        $this->assertNotNull($token->expires_at);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_422(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        foreach ([[$user->email, 'wrong'], ['nobody@example.com', 'secret-password']] as [$email, $password]) {
            $this->postJson('/api/login', ['email' => $email, 'password' => $password, 'device_name' => 'curl'])
                ->assertUnprocessable()
                ->assertExactJson([
                    'status' => 'error',
                    'message' => 'These credentials do not match our records.',
                    'errors' => ['email' => ['These credentials do not match our records.']],
                ]);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $payload = ['email' => 'nobody@example.com', 'password' => 'x', 'device_name' => 'curl'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/login', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('status', 'error');
    }

    public function test_logout_revokes_the_current_token_only(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current', ['chat:ai'])->plainTextToken;
        $user->createToken('other', ['chat:ai']);

        $this->withToken($current)->postJson('/api/logout')->assertOk()->assertJsonPath('status', 'success');

        $this->assertNull(PersonalAccessToken::findToken($current));
        $this->assertSame(['other'], $user->tokens()->pluck('name')->all());
    }

    public function test_logout_still_works_after_the_chat_limit_is_used_up(): void
    {
        config(['llm-hub.rate_limit' => 1]);
        $user = User::factory()->create();
        $token = $user->createToken('current', ['chat:ai'])->plainTextToken;

        $this->withToken($token)->postJson('/api/chat-ai', []);
        $this->withToken($token)->postJson('/api/chat-ai', [])->assertTooManyRequests();

        $this->withToken($token)->postJson('/api/logout')->assertOk();
    }

    public function test_api_404_uses_the_error_format_but_web_pages_do_not(): void
    {
        $this->postJson('/api/nope')->assertNotFound()->assertJsonPath('status', 'error');
        $this->getJson('/api/chat-ai')->assertStatus(405)->assertJsonPath('status', 'error');

        $this->get('/nope')->assertNotFound()->assertDontSee('"status":"error"', false);
    }
}
