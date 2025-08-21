<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Anthropic\Maps;

use BackedEnum;
use Exception;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    /**
     * @param  array<int, Message>  $messages
     * @return array<int, mixed>
     */
    public static function map(array $messages): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Anthropic does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        return array_map(
            fn (Message $message): array => self::mapMessage($message),
            $messages
        );
    }

    /**
     * @param  SystemMessage[]  $messages
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $messages): array
    {
        return array_map(
            fn (Message $message): array => self::mapSystemMessage($message),
            $messages
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message),
            AssistantMessage::class => self::mapAssistantMessage($message),
            ToolResultMessage::class => self::mapToolResultMessage($message),
            default => throw new Exception('Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapSystemMessage(SystemMessage $systemMessage): array
    {
        $providerOptions = $systemMessage->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType', null);

        return array_filter([
            'type' => 'text',
            'text' => $systemMessage->content,
            'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult): array => [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->toolCallId,
                'content' => $toolResult->result,
            ], $message->toolResults),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message): array
    {
        $providerOptions = $message->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType', null);
        $cache_control = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        if ($message->documents() !== []) {
            throw new Exception('Documents are not yet supported by Anthropic on Bedrock.');
        }

        return [
            'role' => 'user',
            'content' => [
                array_filter([
                    'type' => 'text',
                    'text' => $message->text(),
                    'cache_control' => $cache_control,
                ]),
                ...self::mapImageParts($message->images(), $cache_control),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $providerOptions = $message->providerOptions();

        $cacheType = data_get($providerOptions, 'cacheType', null);

        $content = [];

        if (isset($message->additionalContent['messagePartsWithCitations'])) {
            throw new Exception('Citations are not yet supported by Anthropic on Bedrock.');
            // TODO: update once citation support is supported by Anthropic on Bedrock
            // foreach ($message->additionalContent['messagePartsWithCitations'] as $part) {
            //     $content[] = array_filter([
            //         ...$part->toContentBlock(),
            //         'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
            //     ]);
            // }
        } elseif ($message->content !== '' && $message->content !== '0') {

            $content[] = array_filter([
                'type' => 'text',
                'text' => $message->content,
                'cache_control' => $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null,
            ]);
        }

        $toolCalls = $message->toolCalls
            ? array_map(fn (ToolCall $toolCall): array => [
                'type' => 'tool_use',
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->arguments(),
            ], $message->toolCalls)
            : [];

        return [
            'role' => 'assistant',
            'content' => array_merge($content, $toolCalls),
        ];
    }

    /**
     * @param  Image[]  $parts
     * @param  array<string, mixed>|null  $cache_control
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts, ?array $cache_control = null): array
    {
        return array_map(
            fn (Image $image): array => (new ImageMapper($image, $cache_control))->toPayload(),
            $parts
        );
    }
}
