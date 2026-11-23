<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChatAiRequest;
use App\Http\Resources\ChatAiResource;
use App\Models\AiChatLog;
use App\Models\User;
use App\Services\LlmHub\LlmChatService;
use App\Services\LlmHub\LlmRequestFailedException;
use App\Services\LlmHub\LlmResult;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAiController extends Controller
{
    public function store(ChatAiRequest $request, LlmChatService $llm): JsonResponse
    {
        $prompt = $request->validated('prompt');
        $startedAt = hrtime(true);

        try {
            $result = $llm->generate($prompt);
        } catch (LlmRequestFailedException $exception) {
            $this->record($request->user(), $prompt, false, $startedAt, failure: $exception);

            throw $exception;
        }

        $log = $this->record($request->user(), $prompt, false, $startedAt, result: $result);

        return (new ChatAiResource($log))->response()->setStatusCode(201);
    }

    /**
     * Server-sent events: `delta` {text} per chunk, then `done` with the same
     * data as the JSON endpoint, or `error` {message}. Headers are already sent
     * when the provider is called, so a failure arrives as an event, not a status.
     */
    public function stream(ChatAiRequest $request, LlmChatService $llm): StreamedResponse
    {
        $user = $request->user();
        $prompt = $request->validated('prompt');

        return response()->eventStream(function () use ($user, $prompt, $llm): Generator {
            $startedAt = hrtime(true);
            $chunks = $llm->stream($prompt);

            try {
                foreach ($chunks as $delta) {
                    yield new StreamedEvent('delta', ['text' => $delta]);
                }
            } catch (LlmRequestFailedException $exception) {
                $this->record($user, $prompt, true, $startedAt, failure: $exception);

                yield new StreamedEvent('error', ['message' => $exception->getMessage()]);

                return;
            }

            $log = $this->record($user, $prompt, true, $startedAt, result: $chunks->getReturn());

            yield new StreamedEvent('done', (new ChatAiResource($log))->resolve());
        }, endStreamWith: null);
    }

    private function record(
        User $user,
        string $prompt,
        bool $streamed,
        int $startedAt,
        ?LlmResult $result = null,
        ?LlmRequestFailedException $failure = null,
    ): AiChatLog {
        return $user->aiChatLogs()->create([
            'prompt' => $prompt,
            'ai_response' => $result?->text,
            'provider' => $result->provider ?? $failure?->provider,
            'model_used' => $result->model ?? $failure?->model,
            'prompt_tokens' => $result?->promptTokens,
            'completion_tokens' => $result?->completionTokens,
            'total_tokens' => $result?->totalTokens,
            'is_streamed' => $streamed,
            'latency_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'status' => $result === null ? AiChatLog::STATUS_FAILED : AiChatLog::STATUS_SUCCESS,
            'error_message' => $failure?->getMessage(),
        ]);
    }
}
