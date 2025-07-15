<?php

namespace Prism\Bedrock\Schemas\Converse;

use Aws\Result;
use Generator;
use Prism\Bedrock\Contracts\BedrockStreamHandler;
use Prism\Bedrock\HandlesStream;
use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolChoiceMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolMap;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Exceptions\PrismChunkDecodeException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\ResponseBuilder;
use Throwable;

class ConverseStreamHandler extends BedrockStreamHandler
{
    use CallsTools, HandlesStream;

    protected StreamState $state;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->state = new StreamState;

        $this->responseBuilder = new ResponseBuilder;
    }

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    public function handle(Request $request): Generator
    {
        $result = $this->sendRequest($request);

        return $this->processStream($result, $request);
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, int $stepCount = 0): array
    {
        return array_filter([
            'anthropic_version' => 'bedrock-2023-05-31',
            '@http' => [
                'stream' => true,
            ],
            'modelId' => $request->model(),
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'messages' => MessageMap::map($request->messages()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'toolConfig' => $request->tools() === []
                ? null
                : array_filter([
                    'tools' => ToolMap::map($request->tools()),
                    'toolChoice' => $stepCount === 0 ? ToolChoiceMap::map($request->toolChoice()) : null,
                ]),
        ]);
    }

    protected function sendRequest(Request $request): Result
    {
        try {
            $payload = static::buildPayload($request, $this->responseBuilder->steps->count());

            return $this->client->converseStream($payload);
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
