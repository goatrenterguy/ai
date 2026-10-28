<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;

beforeEach(function (): void {
    config(['ai.providers.anthropic' => [
        ...config('ai.providers.anthropic'),
        'key' => 'test-key',
    ]]);
});

test('get file sends correct request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-abc123', 'mime_type' => 'text/plain']),
    ]);

    $response = Files::get('file-abc123', provider: 'anthropic');

    expect($response->id)->toBe('file-abc123')
        ->and($response->raw)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});

test('put file sends multipart upload', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
        provider: 'anthropic',
    );

    expect($response->id)->toBe('file-uploaded123')
        ->and($response->raw)->not->toBeNull();

    $request = sentRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.anthropic.com/v1/files')
        ->and($request->header('Content-Type')[0] ?? '')->toContain('multipart/form-data')
        ->and($request->hasHeader('x-api-key', 'test-key'))->toBeTrue();
});

test('put file forwards provider options into the multipart upload', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-uploaded123']),
    ]);

    Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
        ->withProviderOptions(['custom_field' => 'value'])
        ->put(provider: 'anthropic');

    expect(multipartField(sentRequest(), 'custom_field'))->toBe('value');
});

test('delete file sends correct request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    Files::delete('file-abc123', provider: 'anthropic');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
        && $request->hasHeader('x-api-key', 'test-key'));
});

test('delete file encodes the id as a single path segment', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'file-abc123']),
    ]);

    Files::delete('../skills/skill-123?beta=true', provider: 'anthropic');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.anthropic.com/v1/files/..%2Fskills%2Fskill-123%3Fbeta%3Dtrue');
});

test('download file returns the content of a generated file', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response('a,b'.PHP_EOL.'1,2', 200, ['Content-Type' => 'text/csv; charset=utf-8']),
    ]);

    $response = Files::download('file-abc123', provider: 'anthropic');

    expect($response->id)->toBe('file-abc123')
        ->and($response->content())->toBe('a,b'.PHP_EOL.'1,2')
        ->and($response->mimeType())->toBe('text/csv')
        ->and($response->raw)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123/content'
        && $request->hasHeader('x-api-key', 'test-key')
        && $request->hasHeader('anthropic-beta', 'files-api-2025-04-14'));
});

test('download file throws when the file is not downloadable', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'File is not downloadable.'],
        ], 400),
    ]);

    Files::download('file-uploaded123', provider: 'anthropic');
})->throws(RequestException::class);
