<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Fixtures\Agents\CodeExecutionAgent;

function fakeContainerResponse(string $stopReason, ?string $container, array $content = []): PromiseInterface
{
    return Http::response(array_filter([
        'id' => 'msg_'.uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => $content ?: [['type' => 'text', 'text' => 'Working.']],
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'container' => $container === null ? null : ['id' => $container, 'expires_at' => '2026-10-28T12:00:00Z'],
    ], fn ($value): bool => $value !== null));
}

function fakeContainerStream(string $stopReason, ?string $startContainer, ?string $deltaContainer): PromiseInterface
{
    $start = [
        'type' => 'message_start',
        'message' => [
            'id' => 'msg_'.uniqid(),
            'model' => 'claude-sonnet-4-6',
            'role' => 'assistant',
            'content' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
            'container' => $startContainer === null ? null : ['id' => $startContainer, 'expires_at' => '2026-10-28T12:00:00Z'],
        ],
    ];

    $delta = [
        'type' => 'message_delta',
        'delta' => ['stop_reason' => $stopReason, 'container' => $deltaContainer === null ? null : ['id' => $deltaContainer, 'expires_at' => '2026-10-28T12:00:00Z']],
        'usage' => ['output_tokens' => 5],
    ];

    $events = [
        $start,
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Working.']],
        ['type' => 'content_block_stop', 'index' => 0],
        $delta,
    ];

    return Http::response(
        body: implode("\n\n", array_map(fn (array $event): string => 'data: '.json_encode($event), $events))."\n\n",
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    );
}

function sentContainers(): array
{
    return array_map(fn (array $pair): mixed => $pair[0]->data()['container'] ?? null, Http::recorded()->all());
}

test('every step after the first runs in the container the first step reported', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerResponse('pause_turn', 'container_1'),
            fakeContainerResponse('tool_use', 'container_1', [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'FixedNumberGenerator',
                'input' => (object) [],
            ]]),
            fakeContainerResponse('end_turn', 'container_1'),
        ]),
    ]);

    (new CodeExecutionAgent)->prompt('Run the analysis', provider: 'anthropic');

    expect(sentContainers())->toBe([null, 'container_1', 'container_1']);
});

test('a streamed continuation runs in the container the first step reported', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerStream('pause_turn', null, 'container_1'),
            fakeContainerStream('end_turn', 'container_1', 'container_1'),
        ]),
    ]);

    $container = null;

    $response = (new CodeExecutionAgent)
        ->stream('Run the analysis', provider: 'anthropic')
        ->then(function (StreamedAgentResponse $response) use (&$container): void {
            $container = $response->meta->container;
        });

    iterator_to_array($response);

    expect(sentContainers())->toBe([null, 'container_1'])
        ->and($container)->toBe('container_1');
});

test('a streamed container reported only on message start is carried', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerStream('pause_turn', 'container_1', null),
            fakeContainerStream('end_turn', 'container_1', null),
        ]),
    ]);

    iterator_to_array((new CodeExecutionAgent)->stream('Run the analysis', provider: 'anthropic'));

    expect(sentContainers())->toBe([null, 'container_1']);
});

test('a container set in provider options wins over the carried one', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerResponse('pause_turn', 'container_new'),
            fakeContainerResponse('end_turn', 'container_new'),
        ]),
    ]);

    (new CodeExecutionAgent('container_mine'))->prompt('Run the analysis', provider: 'anthropic');

    expect(sentContainers())->toBe(['container_mine', 'container_mine']);
});

test('a container set in provider options wins over the carried one when streaming', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerStream('pause_turn', null, 'container_new'),
            fakeContainerStream('end_turn', null, 'container_new'),
        ]),
    ]);

    iterator_to_array((new CodeExecutionAgent('container_mine'))->stream('Run the analysis', provider: 'anthropic'));

    expect(sentContainers())->toBe(['container_mine', 'container_mine']);
});

test('the caller reads the container from a finished response', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            fakeContainerResponse('pause_turn', 'container_1'),
            fakeContainerResponse('end_turn', 'container_1'),
        ]),
    ]);

    $response = (new CodeExecutionAgent)->prompt('Run the analysis', provider: 'anthropic');

    expect($response->meta->container)->toBe('container_1')
        ->and($response->steps->map(fn (Step $step): ?string => $step->meta->container)->all())->toBe(['container_1', 'container_1'])
        ->and($response->meta->toArray()['container'])->toBe('container_1');
});

test('a run without code execution sends and reports no container', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $response = (new CodeExecutionAgent)->prompt('Hi', provider: 'anthropic');

    expect(sentContainers())->toBe([null])
        ->and($response->meta->container)->toBeNull();
});
