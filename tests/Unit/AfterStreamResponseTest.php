<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\FinishStreamedResponse;
use App\Http\Responses\AfterStreamResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class AfterStreamResponseTest extends TestCase
{
    public function test_sending_writes_only_the_body(): void
    {
        $response = new AfterStreamResponse('{"components":[]}', 200);

        ob_start();
        $response->sendHeaders();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertSame('{"components":[]}', $output);
    }

    public function test_responses_without_a_livewire_stream_pass_through_untouched(): void
    {
        $original = new Response('ok', 302, ['Location' => '/chat']);

        $result = (new FinishStreamedResponse)->handle(Request::create('/'), fn () => $original);

        $this->assertSame($original, $result);
        $this->assertSame('/chat', $result->headers->get('Location'));
    }
}
