<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Response;

/**
 * The final body of a request whose headers were already sent by a stream
 * (Livewire's wire:stream). Only the content is written: re-sending the
 * status line would raise "headers already sent", which Laravel turns into
 * an error that drops the final payload the browser is waiting for.
 */
class AfterStreamResponse extends Response
{
    public function sendHeaders(?int $statusCode = null): static
    {
        return $this;
    }
}
