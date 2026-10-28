<?php

namespace Laravel\Ai\Exceptions;

class MessageAlreadyStoredException extends AiException
{
    /**
     * Create a new message already stored exception.
     */
    public function __construct(string $message, public readonly string $conversationId, public readonly string $messageId)
    {
        parent::__construct($message);
    }

    /**
     * Create a new exception for the given conversation and message.
     */
    public static function for(string $conversationId, string $messageId): self
    {
        return new self(
            "The conversation [{$conversationId}] already contains a message with the ID [{$messageId}].",
            $conversationId,
            $messageId,
        );
    }
}
