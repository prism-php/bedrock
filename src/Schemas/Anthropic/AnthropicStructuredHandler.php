<?php

namespace Prism\Bedrock\Schemas\Anthropic;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockStructuredHandler;
use Prism\Bedrock\Schemas\Anthropic\Concerns\ExtractsText;
use Prism\Bedrock\Schemas\Anthropic\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Anthropic\Maps\MessageMap;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class AnthropicStructuredHandler extends BedrockStructuredHandler
{
    use ExtractsText;

    protected StructuredResponse $tempResponse;

    protected Response $httpResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    #[\Override]
    public function handle(Request $request): StructuredResponse
    {
        $this->appendMessageForJsonMode($request);

        $this->sendRequest($request);

        $this->prepareTempResponse();

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            toolCalls: [],
            additionalContent: $this->tempResponse->additionalContent
        );

        $request->addMessage($responseMessage);

        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
        ));

        return $this->responseBuilder->toResponse();
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, ?string $apiVersion): array
    {
        return array_filter([
            'anthropic_version' => $apiVersion,
            'messages' => MessageMap::map($request->messages()),
            'max_tokens' => $request->maxTokens(),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
        ], fn (string|float|int|array|null $value): bool => $value !== null);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'invoke',
                static::buildPayload($request, $this->provider->apiVersion($request))
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new StructuredResponse(
            steps: new Collection,
            text: $this->extractText($data),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens', null),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens', null)
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            )
        );
    }

    protected function appendMessageForJsonMode(Request $request): void
    {
        $request->addMessage(new UserMessage(sprintf(
            "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
