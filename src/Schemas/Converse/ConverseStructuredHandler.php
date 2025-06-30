<?php

namespace Prism\Bedrock\Schemas\Converse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockStructuredHandler;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
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

class ConverseStructuredHandler extends BedrockStructuredHandler
{
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
        $this->responseBuilder->addResponseMessage($responseMessage);

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
    public static function buildPayload(Request $request): array
    {
        return array_filter([
            'additionalModelRequestFields' => $request->providerOptions('additionalModelRequestFields'),
            'additionalModelResponseFieldPaths' => $request->providerOptions('additionalModelResponseFieldPaths'),
            'guardrailConfig' => $request->providerOptions('guardrailConfig'),
            'inferenceConfig' => array_filter([
                'maxTokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'topP' => $request->topP(),
            ], fn ($value): bool => $value !== null),
            'messages' => MessageMap::map($request->messages()),
            'performanceConfig' => $request->providerOptions('performanceConfig'),
            'promptVariables' => $request->providerOptions('promptVariables'),
            'requestMetadata' => $request->providerOptions('requestMetadata'),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
        ]);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'converse',
                static::buildPayload($request)
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
            responseMessages: new Collection,
            text: data_get($data, 'output.message.content.0.text', ''),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stopReason')),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.inputTokens'),
                completionTokens: data_get($data, 'usage.outputTokens')
            ),
            meta: new Meta(id: '', model: '') // Not provided in Converse response.

        );
    }

    protected function appendMessageForJsonMode(Request $request): void
    {
        $request->addMessage(new UserMessage(sprintf(
            "%s \n %s",
            $request->providerOptions('jsonModeMessage') ?? 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:',
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
