<?php

namespace Laravel\Ai\Messages;

use Illuminate\Support\Collection;

class UserMessage extends Message
{
    /**
     * The message's attachments.
     */
    public Collection $attachments;

    /**
     * The ID the message should be stored under, when the caller chooses it.
     */
    public ?string $id;

    /**
     * Create a new text conversation message instance.
     */
    public function __construct(string $content, Collection|array $attachments = [], ?string $id = null)
    {
        parent::__construct('user', $content);

        $this->attachments = Collection::wrap($attachments);
        $this->id = $id;
    }
}
