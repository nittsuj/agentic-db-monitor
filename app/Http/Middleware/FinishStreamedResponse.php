<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AfterStreamResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livewire streaming (the chat's wire:stream) sends the HTTP headers and
 * part of the body before the component finishes. Symfony then tries to send
 * the status line again for the final JSON, PHP warns, Laravel converts the
 * warning into an exception and the browser never gets the final payload, so
 * the chat looks stuck until a refresh. Registered as the outermost global
 * middleware, this hands such responses over without their headers.
 */
class FinishStreamedResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::livewireStreamStarted()
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse) {
            return $response;
        }

        return new AfterStreamResponse((string) $response->getContent(), $response->getStatusCode());
    }

    /**
     * True only when this request already sent Livewire's stream headers. A
     * plain headers_sent() is not enough: under PHPUnit any test output makes
     * it true, and every response there would lose its headers.
     */
    public static function livewireStreamStarted(): bool
    {
        if (! headers_sent()) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'X-Livewire-Stream:') === 0) {
                return true;
            }
        }

        return false;
    }
}
