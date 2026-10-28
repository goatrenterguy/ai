<?php

namespace Laravel\Ai\Gateway;

class StepContext
{
    /**
     * @param  string|null  $continuationToken  Provider handle for stateful continuation; null for stateless providers that replay full history.
     * @param  string|null  $container  The code execution container an earlier step of the run reported, for providers that only reuse one when asked.
     */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
        public readonly ?string $container = null,
    ) {}
}
