<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiChatLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiChatLog
 */
class ChatAiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->id,
            'model_used' => $this->model_used,
            'prompt' => $this->prompt,
            'ai_response' => $this->ai_response,
            'timestamp' => $this->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function with(Request $request): array
    {
        return ['status' => 'success'];
    }
}
