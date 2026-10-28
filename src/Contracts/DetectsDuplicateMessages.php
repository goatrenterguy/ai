<?php

namespace Laravel\Ai\Contracts;

interface DetectsDuplicateMessages
{
    /**
     * Determine whether the given conversation already stored a message under the given ID.
     */
    public function hasMessage(string $conversationId, string $messageId): bool;
}
