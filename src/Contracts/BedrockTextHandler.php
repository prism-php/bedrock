<?php

declare(strict_types=1);

namespace Prism\Bedrock\Contracts;

use Illuminate\Http\Client\PendingRequest;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;

abstract class BedrockTextHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
