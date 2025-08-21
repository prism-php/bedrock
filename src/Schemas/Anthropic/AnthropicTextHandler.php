<?php

namespace Prism\Bedrock\Schemas\Anthropic;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockTextHandler;
use Prism\Bedrock\Schemas\Anthropic\Concerns\ExtractsText;
use Prism\Bedrock\Schemas\Anthropic\Concerns\ExtractsToolCalls;
use Prism\Bedrock\Schemas\Anthropic\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Anthropic\Maps\MessageMap;
use Prism\Bedrock\Schemas\Anthropic\Maps\ToolChoiceMap;
use Prism\Bedrock\Schemas\Anthropic\Maps\ToolMap;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class AnthropicTextHandler extends BedrockTextHandler
{
    use CallsTools, ExtractsText, ExtractsToolCalls;

    protected TextResponse $tempResponse;

    protected Response $httpResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    #[\Override]
    public function handle(Request $request): TextResponse
    {
        $this->sendRequest($request);

        $this->prepareTempResponse();

        $responseMessage = new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
        );

        $request->addMessage($responseMessage);

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($request),
            FinishReason::Stop, FinishReason::Length => $this->handleStop($request),
            default => throw new PrismException('Anthropic: unknown finish reason'),
        };
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
            'tools' => ToolMap::map($request->tools()),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
        ], fn (float|int|string|array|null $value): bool => $value !== null);
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

        $this->tempResponse = new TextResponse(
            steps: new Collection,
            text: $this->extractText($data),
            finishReason: FinishReasonMap::map(data_get($data, 'stop_reason', '')),
            toolCalls: $this->extractToolCalls($data),
            toolResults: [],
            usage: new Usage(
                promptTokens: data_get($data, 'usage.input_tokens'),
                completionTokens: data_get($data, 'usage.output_tokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cache_creation_input_tokens'),
                cacheReadInputTokens: data_get($data, 'usage.cache_read_input_tokens')
            ),
            meta: new Meta(
                id: data_get($data, 'id'),
                model: data_get($data, 'model'),
            ),
            messages: new Collection
        );
    }

    protected function handleToolCalls(Request $request): TextResponse
    {
        $toolResults = $this->callTools($request->tools(), $this->tempResponse->toolCalls);
        $message = new ToolResultMessage($toolResults);

        $request->addMessage($message);

        $this->addStep($request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(Request $request): TextResponse
    {
        $this->addStep($request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
        ));
    }
}
