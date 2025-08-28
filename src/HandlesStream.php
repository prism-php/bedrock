<?php

namespace Prism\Bedrock;

use Aws\Result;
use Generator;
use Prism\Bedrock\Exceptions\BedrockException;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;

trait HandlesStream
{
    public function processStream(Result $result, Request $request, int $depth = 0): Generator
    {
        $this->state->reset();

        $this->validateToolCallDepth($request, $depth);

        yield from $this->processStreamChunks($result, $request, $depth);

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolUseFinish($request, $depth);
        }
    }

    protected function validateToolCallDepth(Request $request, int $depth): void
    {
        if ($depth >= $request->maxSteps()) {
            throw new PrismException('Maximum tool call chain depth exceeded');
        }
    }

    protected function processStreamChunks(Result $result, Request $request, int $depth): Generator
    {

        foreach ($result->get('stream') as $event) {

            $outcome = $this->processChunk($event, $request, $depth);

            if ($outcome instanceof Generator) {
                yield from $outcome;
            }

            if ($outcome instanceof Chunk) {
                yield $outcome;
            }
        }
    }

    protected function processChunk(array $chunk, Request $request, int $depth): Generator|Chunk|null
    {
        return match (array_key_first($chunk) ?? null) {
            'messageStart' => $this->handleMessageStart(data_get($chunk, 'messageStart')),
            'contentBlockDelta' => $this->handleContentBlockDelta(data_get($chunk, 'contentBlockDelta')),
            'contentBlockStop' => $this->handleContentBlockStop(),
            'messageStop' => $this->handleMessageStop(data_get($chunk, 'messageStop'), $depth),
            'metadata' => $this->handleMetadata(data_get($chunk, 'metadata')),
            'modelStreamErrorException' => $this->handleException(data_get($chunk, 'modelStreamErrorException')),
            'serviceUnavailableException' => $this->handleException(data_get($chunk, 'serviceUnavailableException')),
            'throttlingException' => $this->handleException(data_get($chunk, 'throttlingException')),
            'validationException' => $this->handleException(data_get($chunk, 'validationException')),
            default => null,
        };
    }

    protected function handleMessageStart(array $chunk): null
    {
        // {
        // messageStart: {
        //      role: assistant
        // }

        return null;
    }

    protected function handleContentBlockDelta(array $chunk): ?Chunk
    {
        if ($text = data_get($chunk, 'delta.text')) {
            return $this->handleTextBlockDelta($text, (int) data_get($chunk, 'contentBlockIndex'));
        }

        if ($reasoningContent = data_get($chunk, 'delta.reasoningContent')) {
            return $this->handleReasoningContentBlockDelta($reasoningContent);
        }

        if ($toolUse = data_get($chunk, 'delta.toolUse')) {
            return $this->handleToolUseBlockDelta($toolUse);
        }

        return null;
    }

    protected function handleTextBlockDelta(string $text, int $contentBlockIndex): Chunk
    {
        $this->state->appendText($text);

        return new Chunk(
            text: $text,
            additionalContent: [
                'contentBlockIndex' => $contentBlockIndex,
            ],
            chunkType: ChunkType::Text
        );
    }

    protected function handleReasoningContentBlockDelta(array $reasoningContent): Chunk
    {
        $text = data_get($reasoningContent, 'reasoningText.text', '');
        $signature = data_get($reasoningContent, 'reasoningText.signature', '');

        $this->state->appendThinking($text);
        $this->state->appendThinkingSignature($signature);

        return new Chunk(
            text: $text,
            chunkType: ChunkType::Thinking
        );
    }

    protected function handleContentBlockStop(): void
    {
        $this->state->resetContentBlock();
    }

    protected function handleMessageStop(array $chunk, int $depth): Generator|Chunk
    {
        $this->state->setStopReason(data_get($chunk, 'stopReason'));

        if ($this->state->isToolUseFinish()) {
            return $this->handleToolUseFinish($chunk, $depth);
        }

        return new Chunk(
            text: $this->state->text(),
            finishReason: FinishReasonMap::map($this->state->stopReason()),
            additionalContent: $this->state->buildAdditionalContent(),
            chunkType: ChunkType::Meta
        );
    }

    protected function handleMetadata(array $chunk): void
    {
        // {"metadata":{"usage":{"inputTokens":11,"outputTokens":48,"totalTokens":59},"metrics":{"latencyMs":1269}}}
        // not sure yet where to store this information.
    }

    protected function handleException(array $chunk): void
    {
        throw new BedrockException(data_get($chunk, 'message'));
    }

    protected function handleToolUseBlockDelta(array $toolUse): void
    {
        throw new \Exception('Tool use not yet supported');
    }

    public function handleToolUseFinish(Request $request, int $depth): void
    {
        throw new \Exception('Tool use not yet supported');
    }
}
