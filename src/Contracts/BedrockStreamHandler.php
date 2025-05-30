<?php

namespace Prism\Bedrock\Contracts;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Generator;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Text\Request;

abstract class BedrockStreamHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected BedrockRuntimeClient $client
    ) {}

    abstract public function handle(Request $request): Generator;
}
