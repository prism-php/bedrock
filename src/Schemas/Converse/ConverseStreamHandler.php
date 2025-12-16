<?php

namespace Prism\Bedrock\Schemas\Converse;

use Aws\Api\Parser\DecodingEventStreamIterator;
use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Schemas\Converse\Maps\CitationsMapper;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\ValueObjects\ConverseStreamState;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\CitationEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class ConverseStreamHandler
{
    use CallsTools;

    protected Response $httpResponse;

    protected ConverseStreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new ConverseStreamState;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, int $stepCount = 0): array
    {
        return ConverseTextHandler::buildPayload(
            $request,
            $stepCount
        );
    }

    protected function sendRequest(Request $request): Response
    {
        return $this->client
            ->withOptions(['stream' => true])
            ->post(
                'converse-stream',
                static::buildPayload($request)
            );
    }

    /**
     * @return Generator<StreamEvent>
     */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        $this->state->reset();

        $this->state
            ->withModel($request->model());

        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $decoder = new DecodingEventStreamIterator($stream);
        } else {
            $decoder = new NonSeekableStreamDecodingEventStreamIterator($stream);
        }

        foreach ($decoder as $event) {
            $streamEvent = $this->processEvent($event);

            if ($streamEvent instanceof Generator) {
                yield from $streamEvent;
            } elseif ($streamEvent instanceof StreamEvent) {
                yield $streamEvent;
            }
        }

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $this->mapToolCalls(), $depth);
        }
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, array $toolCalls, int $depth): Generator
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->resolveTool($toolCall->name, $request->tools());

            try {
                $result = call_user_func_array(
                    $tool->handle(...),
                    $toolCall->arguments()
                );

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $result,
                );

                $toolResults[] = $toolResult;

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $toolResult,
                    messageId: $this->state->messageId(),
                    success: true
                );
            } catch (Throwable $e) {
                $errorResultObj = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: []
                );

                yield new ToolResultEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    toolResult: $errorResultObj,
                    messageId: $this->state->messageId(),
                    success: false,
                    error: $e->getMessage()
                );
            }
        }

        if ($toolResults !== []) {
            $request->addMessage(new AssistantMessage(
                content: $this->state->currentText(),
                toolCalls: $toolCalls
            ));

            $request->addMessage(new ToolResultMessage($toolResults));

            // Continue streaming if within step limit
            $depth++;
            if ($depth < $request->maxSteps()) {
                $this->state->reset();
                $nextResponse = $this->sendRequest($request);
                yield from $this->processStream($nextResponse, $request, $depth);
            }
        }
    }

    protected function shouldContinue(Request $request, int $depth): bool
    {
        return $depth < $request->maxSteps();
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $input = data_get($toolCall, 'input');
            if (is_string($input) && $this->isValidJson($input)) {
                $input = json_decode($input, true);
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: $input
            );
        }, $this->state->toolCalls()));
    }

    protected function isValidJson(string $string): bool
    {
        if ($string === '' || $string === '0') {
            return false;
        }

        try {
            json_decode($string, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function processEvent(array $event): null|StreamEvent|Generator
    {
        $json = json_decode((string) $event['payload'], true);

        return match ($event['headers'][':event-type']) {
            'messageStart' => $this->handleMessageStart($json),
            'contentBlockStart' => $this->handleContentBlockStart($json),
            'contentBlockDelta' => $this->handleContentBlockDelta($json),
            'contentBlockStop' => $this->handleContentBlockStop($json),
            'messageStop' => $this->handleMessageStop($json),
            'metadata' => $this->handleMetadata($json),
            'internalServerException',
            'throttlingException',
            'modelStreamErrorException',
            'serviceUnavailableException',
            'validationException' => $this->handleError($json),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStart(array $event): ?StreamEvent
    {
        $blockType = (bool) data_get($event, 'start.toolUse')
            ? 'tool_use' : 'text';

        $blockIndex = (int) data_get($event, 'contentBlockIndex');

        $this->state->withBlockContext($blockIndex, $blockType);

        if ($blockType === 'tool_use') {
            $this->state->addToolCall($blockIndex, [
                'id' => data_get($event, 'start.toolUse.toolUseId'),
                'name' => data_get($event, 'start.toolUse.name'),
                'input' => '',
            ]);

            return null;
        }

        return new TextStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockDelta(array $event): null|StreamEvent|Generator
    {
        $this->state->withBlockIndex($event['contentBlockIndex']);
        $delta = $event['delta'];

        return match (array_keys($delta)[0] ?? null) {
            'text' => $this->handleTextDelta($delta['text']),
            'citation' => $this->handleCitationDelta($delta['citation']),
            'reasoningContent' => $this->handleReasoningContentDelta($delta['reasoningContent']),
            'toolUse' => $this->handleToolUseDelta($delta['toolUse']),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleContentBlockStop(array $event): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => new TextCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'tool_use' => $this->handleToolUseComplete(),
            'thinking' => new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId()
            ),
            default => null,
        };

        $this->state->resetBlockContext();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStart(array $event): StreamStartEvent
    {
        $this->state
            ->withMessageId(EventID::generate());

        return new StreamStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            model: $this->state->model(),
            provider: Bedrock::KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMessageStop(array $event): void
    {
        $this->state->withFinishReason(FinishReasonMap::map(data_get($event, 'stopReason')));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function handleMetadata(array $event): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? throw new PrismException('Finish reason not set'),
            usage: new Usage(
                promptTokens: data_get($event, 'usage.inputTokens', 0),
                completionTokens: data_get($event, 'usage.outputTokens', 0),
                cacheWriteInputTokens: data_get($event, 'usage.cacheWriteInputTokens', 0),
                cacheReadInputTokens: data_get($event, 'usage.cacheReadInputTokens', 0),
            ),
            citations: $this->state->citations() !== [] ? $this->state->citations() : null
        );
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    protected function handleToolUseStart(array $contentBlock): null
    {
        $blockIndex = $this->state->currentBlockIndex();
        if ($this->state->currentBlockType() !== null && $blockIndex !== null) {
            $this->state->addToolCall($blockIndex, [
                'id' => $contentBlock['id'] ?? EventID::generate(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return never
     */
    protected function handleError(array $event)
    {
        if ($event[':headers']['event-type'] === 'throttlingException') {
            throw PrismRateLimitedException::make();
        }

        throw PrismException::providerResponseError(vsprintf(
            'Bedrock Converse Stream Error: %s',
            $event[':headers']['event-type']
        ));
    }

    protected function handleTextDelta(string $text): ?TextDeltaEvent
    {
        if ($text === '') {
            return null;
        }

        $this->state->appendText($text);

        return new TextDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $text,
            messageId: $this->state->messageId()
        );
    }

    /**
     * @param  array<string, mixed>  $citationData
     */
    protected function handleCitationDelta(array $citationData): CitationEvent
    {
        // Map citation data using CitationsMapper
        $citation = CitationsMapper::mapCitationFromConverse($citationData);

        // Create MessagePartWithCitations for aggregation
        $messagePartWithCitations = new MessagePartWithCitations(
            outputText: $this->state->currentText(),
            citations: [$citation]
        );

        // Store for later aggregation
        $this->state->addCitation($messagePartWithCitations);

        return new CitationEvent(
            id: EventID::generate(),
            timestamp: time(),
            citation: $citation,
            messageId: $this->state->messageId(),
            blockIndex: $this->state->currentBlockIndex()
        );
    }

    /**
     * @param  array<string, mixed>  $reasoningContent
     * @return Generator<StreamEvent>
     */
    protected function handleReasoningContentDelta(array $reasoningContent): Generator
    {
        $thinking = $reasoningContent['text'] ?? '';

        if ($thinking === '') {
            return;
        }

        $this->state->withBlockType('thinking');

        if ($this->state->reasoningId() === '' || $this->state->reasoningId() === '0') {
            $this->state->withReasoningId(EventID::generate());

            yield new ThinkingStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId()
            );
        }

        $this->state->appendThinking($thinking);

        yield new ThinkingEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $thinking,
            reasoningId: $this->state->reasoningId()
        );
    }

    /**
     * @param  array<string, mixed>  $toolUse
     */
    protected function handleToolUseDelta(array $toolUse): null
    {
        $jsonDelta = data_get($toolUse, 'input');

        $blockIndex = $this->state->currentBlockIndex();

        if ($blockIndex !== null) {
            $this->state->appendToolCallInput($blockIndex, $jsonDelta);
        }

        return null;
    }

    protected function handleToolUseComplete(): ?ToolCallEvent
    {
        $blockIndex = $this->state->currentBlockIndex();
        if ($blockIndex === null) {
            return null;
        }

        $toolCall = $this->state->toolCalls()[$blockIndex];
        $input = $toolCall['input'];

        // Parse the JSON input
        if (is_string($input) && json_validate($input)) {
            $input = json_decode($input, true);
        } elseif (is_string($input) && $input !== '') {
            // If it's not valid JSON but not empty, wrap in array
            $input = ['input' => $input];
        } else {
            $input = [];
        }

        $toolCallObj = new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['name'],
            arguments: $input,
        );

        return new ToolCallEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolCall: $toolCallObj,
            messageId: $this->state->messageId()
        );
    }
}
