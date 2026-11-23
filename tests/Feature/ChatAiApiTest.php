<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Responses\AfterStreamResponse;
use App\Models\AiChatLog;
use App\Models\AiSetting;
use App\Models\User;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use Throwable;

class ChatAiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_a_401_json_error(): void
    {
        $this->postJson('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'error', 'message' => 'Unauthenticated.']);
    }

    public function test_token_without_the_chat_ability_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $this->postJson('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonMissingPath('errors');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPrompts(): array
    {
        return [
            'missing' => [null],
            'blank after trim' => ['   '],
            'array' => [['Halo']],
            'number' => [123],
            'too long' => [str_repeat('a', 1001)],
        ];
    }

    #[DataProvider('invalidPrompts')]
    public function test_invalid_prompt_is_rejected(mixed $prompt): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        $fake = Prism::fake();

        $this->postJson('/api/chat-ai', ['prompt' => $prompt])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors('prompt');

        $fake->assertCallCount(0);
        $this->assertSame(0, AiChatLog::count());
    }

    public function test_prompt_of_exactly_1000_characters_is_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        Prism::fake([TextResponseFake::make()->withText('OK')]);

        $this->postJson('/api/chat-ai', ['prompt' => str_repeat('a', 1000)])->assertCreated();
    }

    public function test_success_returns_the_exact_shape_and_logs_the_exchange(): void
    {
        $this->travelTo('2026-08-28 09:30:00');
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat:ai']);
        Prism::fake([
            TextResponseFake::make()
                ->withText('Middleware adalah lapisan penyaring request.')
                ->withUsage(new Usage(12, 30)),
        ]);

        $response = $this->postJson('/api/chat-ai', ['prompt' => '  Jelaskan middleware!  ']);

        $log = AiChatLog::sole();
        $response->assertCreated()->assertExactJson([
            'status' => 'success',
            'data' => [
                'session_id' => $log->id,
                'model_used' => 'qwen3:14b',
                'prompt' => 'Jelaskan middleware!',
                'ai_response' => 'Middleware adalah lapisan penyaring request.',
                'timestamp' => '2026-08-28 09:30:00',
            ],
        ]);
        $this->assertIsInt($response->json('data.session_id'));
        $this->assertTrue($log->user->is($user));
        $this->assertSame('openai_compatible', $log->provider);
        $this->assertSame([12, 30, 42], [$log->prompt_tokens, $log->completion_tokens, $log->total_tokens]);
        $this->assertSame(AiChatLog::STATUS_SUCCESS, $log->status);
        $this->assertFalse($log->is_streamed);
    }

    public function test_the_active_provider_and_themed_system_prompt_are_sent(): void
    {
        AiSetting::create(['provider' => 'openai_compatible', 'model' => 'qwen3:14b', 'base_url' => 'https://senopati.its.ac.id/llm-gateway/v1', 'api_key' => 'its-key', 'is_active' => false]);
        AiSetting::create(['provider' => 'dahl', 'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', 'base_url' => 'https://inference.dahl.global/v1', 'api_key' => 'dahl-key', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        $fake = Prism::fake([TextResponseFake::make()->withText('OK')]);

        $this->postJson('/api/chat-ai', ['prompt' => 'Apa itu indeks?'])
            ->assertCreated()
            ->assertJsonPath('data.model_used', 'deepseek-ai/DeepSeek-V4-Flash-0731');

        $fake->assertProviderConfig(['api_key' => 'dahl-key', 'url' => 'https://inference.dahl.global/v1']);
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('deepseek-ai/DeepSeek-V4-Flash-0731', $requests[0]->model());
            $this->assertSame('Apa itu indeks?', $requests[0]->prompt());
            $this->assertSame(config('llm-hub.system_prompt'), $requests[0]->systemPrompts()[0]->content);
            $this->assertEmpty($requests[0]->tools());
        });
        $this->assertSame('dahl', AiChatLog::sole()->provider);
        $this->assertStringContainsString('Agentic DB Monitor', config('llm-hub.system_prompt'));
    }

    public function test_think_blocks_are_removed_from_the_reply(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        Prism::fake([TextResponseFake::make()->withText('<think>rahasia</think> Gunakan indeks.')]);

        $this->postJson('/api/chat-ai', ['prompt' => 'Query lambat?'])
            ->assertCreated()
            ->assertJsonPath('data.ai_response', 'Gunakan indeks.');
    }

    /**
     * @return array<string, array{0: Throwable, 1: int}>
     */
    public static function providerFailures(): array
    {
        return [
            'bad key' => [PrismException::providerResponseError('Unauthorized', 401), 502],
            'rate limited' => [PrismException::providerResponseError('Too many', 429), 429],
            'server error' => [PrismException::providerResponseError('Down', 500), 503],
            'network' => [new ConnectionException('cURL error 28: Operation timed out'), 503],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_is_reported_and_logged(Throwable $failure, int $status): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        $this->failingProvider($failure);

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Halo'])->assertStatus($status);

        $this->assertSame(['status', 'message'], array_keys($response->json()));
        $this->assertSame('error', $response->json('status'));
        $log = AiChatLog::sole();
        $this->assertSame(AiChatLog::STATUS_FAILED, $log->status);
        $this->assertNull($log->ai_response);
        $this->assertSame($response->json('message'), $log->error_message);
        $this->assertSame('qwen3:14b', $log->model_used);
    }

    public function test_chat_is_rate_limited_per_user(): void
    {
        config(['llm-hub.rate_limit' => 2]);
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        Prism::fake(array_fill(0, 2, TextResponseFake::make()->withText('OK')));

        $this->postJson('/api/chat-ai', ['prompt' => 'a'])->assertCreated();
        $this->postJson('/api/chat-ai', ['prompt' => 'b'])->assertCreated();
        $this->postJson('/api/chat-ai', ['prompt' => 'c'])
            ->assertTooManyRequests()
            ->assertJsonPath('status', 'error')
            ->assertHeader('Retry-After');
    }

    public function test_stream_sends_deltas_then_done_and_logs_the_exchange(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        Prism::fake([
            TextResponseFake::make()
                ->withText('<think>internal</think>Gunakan EXPLAIN untuk query lambat.')
                ->withUsage(new Usage(5, 7)),
        ])->withFakeChunkSize(4);

        $response = $this->post('/api/chat-ai/stream', ['prompt' => 'Query lambat?'], ['Accept' => 'text/event-stream']);

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        // FinishStreamedResponse only reacts to Livewire streams; SSE must pass through untouched.
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertNotInstanceOf(AfterStreamResponse::class, $response->baseResponse);

        $events = $this->parseEvents($response->streamedContent());
        $deltas = array_filter($events, fn (array $event): bool => $event['event'] === 'delta');
        $done = end($events);

        $this->assertGreaterThan(1, count($deltas));
        $this->assertSame(
            'Gunakan EXPLAIN untuk query lambat.',
            implode('', array_map(fn (array $event): string => $event['data']['text'], $deltas)),
        );
        $log = AiChatLog::sole();
        $this->assertSame('done', $done['event']);
        $this->assertSame($log->id, $done['data']['session_id']);
        $this->assertSame('Gunakan EXPLAIN untuk query lambat.', $done['data']['ai_response']);
        $this->assertTrue($log->is_streamed);
        $this->assertSame(12, $log->total_tokens);
        $this->assertSame(AiChatLog::STATUS_SUCCESS, $log->status);
    }

    public function test_stream_failure_sends_an_error_event_and_logs_it(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat:ai']);
        $this->failingProvider(PrismException::providerResponseError('Unauthorized', 401));

        $response = $this->post('/api/chat-ai/stream', ['prompt' => 'Halo'], ['Accept' => 'text/event-stream']);

        $events = $this->parseEvents($response->streamedContent());
        $this->assertSame('error', $events[0]['event']);
        $this->assertStringContainsString('API key', $events[0]['data']['message']);
        $log = AiChatLog::sole();
        $this->assertSame(AiChatLog::STATUS_FAILED, $log->status);
        $this->assertTrue($log->is_streamed);
    }

    public function test_stream_requires_a_token(): void
    {
        $this->postJson('/api/chat-ai/stream', ['prompt' => 'Halo'])->assertUnauthorized();
    }

    private function failingProvider(Throwable $failure): void
    {
        app()->instance(PrismManager::class, new class($failure) extends PrismManager
        {
            public function __construct(private readonly Throwable $failure) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                return new class($this->failure) extends Provider
                {
                    public function __construct(private readonly Throwable $failure) {}

                    public function text(TextRequest $request): TextResponse
                    {
                        throw $this->failure;
                    }

                    public function stream(TextRequest $request): Generator
                    {
                        throw $this->failure;
                    }
                };
            }
        });
    }

    /**
     * @return list<array{event: string, data: mixed}>
     */
    private function parseEvents(string $body): array
    {
        $events = [];

        foreach (preg_split('/\n\n/', trim($body)) as $block) {
            preg_match('/^event: (.+)\ndata: (.*)$/s', $block, $match);
            $events[] = ['event' => $match[1], 'data' => json_decode($match[2], true)];
        }

        return $events;
    }

    public function test_old_chat_logs_are_pruned_and_recent_ones_kept(): void
    {
        config(['llm-hub.retention_days' => 30]);
        $user = User::factory()->create();
        $row = fn () => $user->aiChatLogs()->create([
            'prompt' => 'p', 'provider' => 'x', 'model_used' => 'm', 'status' => AiChatLog::STATUS_SUCCESS,
        ]);

        $this->travel(-31)->days();
        $old = $row();
        $this->travelBack();
        $recent = $row();

        $this->artisan('model:prune', ['--model' => [AiChatLog::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_long_error_messages_are_stored_whole(): void
    {
        $user = User::factory()->create();
        $log = $user->aiChatLogs()->create([
            'prompt' => 'p', 'provider' => 'x', 'model_used' => 'm',
            'status' => AiChatLog::STATUS_FAILED, 'error_message' => str_repeat('e', 1000),
        ]);

        $this->assertSame(1000, strlen($log->fresh()->error_message));
    }
}
