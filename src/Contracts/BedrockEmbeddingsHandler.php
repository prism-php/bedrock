<?php

declare(strict_types=1);

namespace Prism\Bedrock\Contracts;

use Illuminate\Http\Client\PendingRequest;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response;

abstract class BedrockEmbeddingsHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
