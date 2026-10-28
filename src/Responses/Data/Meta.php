<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class Meta implements Arrayable, JsonSerializable
{
    /** @var Collection<int, Citation> */
    public Collection $citations;

    /**
     * @param  Collection<int, Citation>|null  $citations
     * @param  string|null  $container  The provider's code execution container, when one was used.
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        ?Collection $citations = null,
        public ?string $container = null,
    ) {
        $this->citations = $citations ?? new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'citations' => $this->citations
                ? $this->citations->all()
                : [],
            ...($this->container !== null ? ['container' => $this->container] : []),
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
