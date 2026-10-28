<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider;

class ContainerUpload extends File implements Arrayable, HasProviderId, JsonSerializable
{
    use CanBeRetrievedOrDeletedFromProvider;

    public function __construct(public string $id) {}

    /**
     * Create a new container upload for the stored file with the given ID.
     */
    public static function fromId(string $id): self
    {
        return new self($id);
    }

    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'container-upload',
            'id' => $this->id,
            'name' => $this->name(),
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
