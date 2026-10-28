<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\FileResponse;

trait CreatesDownloadedFileResponses
{
    /**
     * Create a file response from the given file content download.
     */
    protected function downloadedFileResponse(string $fileId, Response $response): FileResponse
    {
        $mimeType = trim(Str::before($response->header('Content-Type'), ';'));

        return (new FileResponse(
            id: $fileId,
            mimeType: $mimeType !== '' ? $mimeType : null,
            content: $response->body(),
        ))->withRawResponse($response);
    }
}
