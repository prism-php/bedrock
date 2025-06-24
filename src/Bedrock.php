<?php

namespace Prism\Bedrock;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Bedrock extends Provider
{
    use InitializesClient;

    const KEY = 'bedrock';

    public function __construct(
        #[\SensitiveParameter] protected Credentials $credentials,
        protected string $region
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $schema = $this->schema($request);

        $handler = $schema->textHandler();

        if ($handler === null) {
            throw new PrismException("Prism Bedrock does not support text for the {$schema->value} apiSchema.");
        }

        $client = $this->client(
            $request,
            $request->clientOptions(),
            $request->clientRetry()
        );

        $handler = new $handler($this, $client);

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $schema = $this->schema($request);

        $handler = $schema->structuredHandler();

        if ($handler === null) {
            throw new PrismException("Prism Bedrock does not support structured for the {$schema->value} apiSchema.");
        }

        $client = $this->client(
            $request,
            $request->clientOptions(),
            $request->clientRetry()
        );

        $handler = new $handler($this, $client);

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingsResponse
    {
        $schema = $this->schema($request);

        $handler = $schema->embeddingsHandler();

        if ($handler === null) {
            throw new PrismException("Prism Bedrock does not support embeddings for the {$schema->value} apiSchema.");
        }

        $client = $this->client(
            $request,
            $request->clientOptions(),
            $request->clientRetry()
        );

        $handler = new $handler($this, $client);

        return $handler->handle($request);
    }

    public function schema(PrismRequest $request): BedrockSchema
    {
        $override = $request->providerOptions();

        $override = data_get($override, 'apiSchema', null);

        return $override ?? BedrockSchema::fromModelString($request->model());
    }

    public function apiVersion(PrismRequest $request): ?string
    {
        return $this->schema($request)->defaultApiVersion();
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(TextRequest|StructuredRequest|EmbeddingRequest $request, array $options = [], array $retry = []): PendingRequest
    {
        $model = $request->model();

        $enableCaching = $request instanceof EmbeddingRequest
            ? false
            : $request->providerOptions('enableCaching') ?? false;

        return $this->baseClient()
            ->acceptJson()
            ->withHeader('explicitPromptCaching', $enableCaching ? 'enabled' : 'disabled')
            ->contentType('application/json')
            ->withOptions($options)
            ->retry(...$retry)
            ->baseUrl("https://bedrock-runtime.{$this->region}.amazonaws.com/model/$model/")
            ->beforeSending(function (Request $request) {
                $request = $request->toPsrRequest();

                $signature = new SignatureV4('bedrock', $this->region);

                return $signature->signRequest($request, $this->credentials);
            })
            ->throw();
    }
}
