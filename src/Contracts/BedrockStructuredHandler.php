<?php

declare(strict_types=1);

namespace Prism\Bedrock\Contracts;

use Illuminate\Http\Client\PendingRequest;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;

abstract class BedrockStructuredHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): StructuredResponse;
}
