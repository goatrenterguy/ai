<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class CodeExecutionAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(public ?string $container = null) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant that runs code.';
    }

    public function tools(): iterable
    {
        return [
            new CodeExecution,
            new FixedNumberGenerator,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->container === null ? [] : ['container' => $this->container];
    }
}
