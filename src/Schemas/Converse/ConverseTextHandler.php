<?php

namespace Prism\Bedrock\Schemas\Converse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockTextHandler;
use Prism\Bedrock\Schemas\Converse\Concerns\ExtractsToolCalls;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolChoiceMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolMap;
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

class ConverseTextHandler extends BedrockTextHandler
{
    use CallsTools, ExtractsToolCalls;

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
    public static function buildPayload(Request $request, int $stepCount = 0): array
    {
        return array_filter([
            'inferenceConfig' => array_filter([
                'maxTokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'topP' => $request->topP(),
            ], fn (mixed $value): bool => $value !== null),
            'messages' => MessageMap::map($request->messages()),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
            'toolConfig' => $request->tools() === []
                ? null
                : array_filter([
                    'tools' => ToolMap::map($request->tools()),
                    'toolChoice' => $stepCount === 0 ? ToolChoiceMap::map($request->toolChoice()) : null,
                ]),
            'additionalModelRequestFields' => $request->providerOptions('additionalModelRequestFields'),
            'additionalModelResponseFieldPaths' => $request->providerOptions('additionalModelResponseFieldPaths'),
            'guardrailConfig' => $request->providerOptions('guardrailConfig'),
            'performanceConfig' => $request->providerOptions('performanceConfig'),
            'promptVariables' => $request->providerOptions('promptVariables'),
            'requestMetadata' => $request->providerOptions('requestMetadata'),
        ]);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'converse',
                static::buildPayload($request, $this->responseBuilder->steps->count())
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
            text: data_get($data, 'output.message.content.0.text', ''),
            finishReason: FinishReasonMap::map(data_get($data, 'stopReason')),
            toolCalls: $this->extractToolCalls($data),
            toolResults: [],
            usage: new Usage(
                promptTokens: data_get($data, 'usage.inputTokens'),
                completionTokens: data_get($data, 'usage.outputTokens')
            ),
            meta: new Meta(id: '', model: ''), // Not provided in Converse response.
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
