<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\MessageAlreadyStoredException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

function clientIdParticipant(): object
{
    return new class
    {
        public int $id = 1;
    };
}

function clientId(): string
{
    $id = (string) Str::uuid7();

    // A client's ID always predates the request that carries it...
    Carbon::setTestNow(now()->addSecond());

    return $id;
}

function clientIdUserRows(?string $conversationId = null): Collection
{
    return DB::table('agent_conversation_messages')
        ->where('role', 'user')
        ->when($conversationId, fn ($query) => $query->where('conversation_id', $conversationId))
        ->orderBy('id')
        ->pluck('id');
}

test('a remembered prompt stores its user message under the id the caller chose', function (): void {
    RememberingAssistantAgent::fake(['Hello']);

    $id = clientId();

    $response = (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $id));

    expect($response->userMessageId)->toBe($id)
        ->and(DB::table('agent_conversation_messages')->where('id', $id)->value('content'))->toBe('Hi')
        ->and(strcmp($response->assistantMessageId, $id))->toBeGreaterThan(0);
});

test('a streamed prompt stores its user message under the id the caller chose', function (): void {
    RememberingAssistantAgent::fake(['Hello', 'Again']);

    $participant = clientIdParticipant();

    $conversationId = (new RememberingAssistantAgent)->forUser($participant)->prompt('Hi')->conversationId;

    $id = clientId();

    $response = (new RememberingAssistantAgent)->continue($conversationId, as: $participant)->stream(new UserMessage('More', id: $id));

    foreach ($response as $event) {
        //
    }

    expect($response->userMessageId)->toBe($id)
        ->and(clientIdUserRows($conversationId)->last())->toBe($id);
});

test('a resent message is neither stored nor answered again', function (): void {
    RememberingAssistantAgent::fake(['Hello', 'Twice']);

    $participant = clientIdParticipant();

    $id = clientId();

    $conversationId = (new RememberingAssistantAgent)->forUser($participant)->prompt(new UserMessage('Hi', id: $id))->conversationId;

    $rows = DB::table('agent_conversation_messages')->count();

    $agent = (new RememberingAssistantAgent)->continue($conversationId, as: $participant);

    expect(fn () => $agent->prompt(new UserMessage('Hi', id: $id)))
        ->toThrow(MessageAlreadyStoredException::class, "already contains a message with the ID [{$id}]");

    expect(DB::table('agent_conversation_messages')->count())->toBe($rows);
});

test('the duplicate exception names the conversation and message', function (): void {
    $exception = MessageAlreadyStoredException::for('conversation-1', 'message-1');

    expect($exception->conversationId)->toBe('conversation-1')
        ->and($exception->messageId)->toBe('message-1');
});

test('an id that would sort before the conversation history is replaced by a generated one', function (): void {
    RememberingAssistantAgent::fake(['Hello', 'Again']);

    $participant = clientIdParticipant();

    $stale = (string) Str::uuid7(now()->subMinute());

    $conversationId = (new RememberingAssistantAgent)->forUser($participant)->prompt('Hi')->conversationId;

    $response = (new RememberingAssistantAgent)->continue($conversationId, as: $participant)->prompt(new UserMessage('More', id: $stale));

    expect($response->userMessageId)->not->toBe($stale)
        ->and(clientIdUserRows($conversationId)->last())->toBe($response->userMessageId);
});

test('an id ahead of the server clock is replaced by a generated one', function (): void {
    RememberingAssistantAgent::fake(['Hello']);

    $future = (string) Str::uuid7(now()->addHour());

    $response = (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $future));

    expect($response->userMessageId)->not->toBe($future)
        ->and(strcmp($response->assistantMessageId, $response->userMessageId))->toBeGreaterThan(0);
});

test('an id that is not a lowercase uuid is replaced by a generated one', function (string $id): void {
    RememberingAssistantAgent::fake(['Hello']);

    $response = (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $id));

    expect($response->userMessageId)->not->toBe($id)
        ->and(Str::isUuid($response->userMessageId))->toBeTrue();
})->with([
    'nanoid' => 'aBc123XyZ0987654',
    'uppercase' => fn () => strtoupper((string) Str::uuid7(now()->subSecond())),
]);

test('an id already stored in another conversation is replaced by a generated one', function (): void {
    RememberingAssistantAgent::fake(['Hello', 'Hello']);

    $id = clientId();

    (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $id));

    $response = (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $id));

    expect($response->userMessageId)->not->toBe($id)
        ->and(DB::table('agent_conversation_messages')->where('role', 'user')->count())->toBe(2);
});

test('the database store reports the messages a conversation stored', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');
    $otherId = $store->storeConversation('user', 1, 'Other');

    $id = $store->storeUserMessage($conversationId, 'user', 1, RememberingAssistantAgent::class, new UserMessage('Hi'));

    expect($store->hasMessage($conversationId, $id))->toBeTrue()
        ->and($store->hasMessage($otherId, $id))->toBeFalse()
        ->and($store->hasMessage($conversationId, (string) Str::uuid7()))->toBeFalse();
});

test('a store that cannot detect duplicates still receives the id the caller chose', function (): void {
    RememberingAssistantAgent::fake(['Hello', 'Again']);

    $store = new class implements ConversationStore
    {
        /** @var list<?string> */
        public array $ids = [];

        public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
        {
            return null;
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
        {
            return $id ?? 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
        {
            $this->ids[] = $message->id;

            return 'user-'.count($this->ids);
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }

        public function storeApprovalResults(string $conversationId, array $toolResults): void
        {
            //
        }
    };

    app()->instance(ConversationStore::class, $store);

    $participant = clientIdParticipant();

    (new RememberingAssistantAgent)->continue('conversation-1', as: $participant)->prompt(new UserMessage('Hi', id: 'client-1'));
    (new RememberingAssistantAgent)->continue('conversation-1', as: $participant)->prompt(new UserMessage('Hi', id: 'client-1'));

    // Without the optional contract the middleware cannot tell a resend apart, so it behaves as it always has...
    expect($store->ids)->toBe(['client-1', 'client-1']);
});

test('a revised prompt keeps the id its user message is stored under', function (): void {
    RememberingAssistantAgent::fake(['Hello']);

    $id = clientId();

    (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt(new UserMessage('Hi', id: $id));

    RememberingAssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messageId === $id
        && $prompt->append('More')->messageId === $id
        && $prompt->withTools([])->messageId === $id);
});

test('a plain string prompt has no caller chosen id', function (): void {
    RememberingAssistantAgent::fake(['Hello']);

    $response = (new RememberingAssistantAgent)->forUser(clientIdParticipant())->prompt('Hi');

    RememberingAssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->messageId === null);

    expect(Str::isUuid($response->userMessageId))->toBeTrue();
});
