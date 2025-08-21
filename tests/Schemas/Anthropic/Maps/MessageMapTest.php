<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic\Maps;

use Prism\Bedrock\Schemas\Anthropic\Maps\MessageMap;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps user messages', function (): void {
    expect(MessageMap::map([
        new UserMessage('Who are you?'),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Who are you?'],
        ],
    ]]);
});

it('maps user messages with images from path', function (): void {
    $mappedMessage = MessageMap::map([
        new UserMessage('Who are you?', [
            Image::fromPath('tests/Fixtures/test-image.png'),
        ]),
    ]);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image');
    expect(data_get($mappedMessage, '0.content.1.source.type'))
        ->toBe('base64');
    expect(data_get($mappedMessage, '0.content.1.source.data'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
    expect(data_get($mappedMessage, '0.content.1.source.media_type'))
        ->toBe('image/png');
});

it('maps user messages with images from base64', function (): void {
    $mappedMessage = MessageMap::map([
        new UserMessage('Who are you?', [
            Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-image.png')), 'image/png'),
        ]),
    ]);

    expect(data_get($mappedMessage, '0.content.1.type'))
        ->toBe('image');
    expect(data_get($mappedMessage, '0.content.1.source.type'))
        ->toBe('base64');
    expect(data_get($mappedMessage, '0.content.1.source.data'))
        ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
    expect(data_get($mappedMessage, '0.content.1.source.media_type'))
        ->toBe('image/png');
});

it('does not maps user messages with images from url', function (): void {
    MessageMap::map([
        new UserMessage('Who are you?', [
            Image::fromUrl('https://storage.echolabs.dev/assets/logo.png'),
        ]),
    ]);
})->throws(PrismException::class);

it('maps assistant message', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx'),
    ]))->toContain([
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'text',
                'text' => 'I am Nyx',
            ],
        ],
    ]);
});

it('maps assistant message with tool calls', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx', [
            new ToolCall(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ]
            ),
        ]),
    ]))->toBe([
        [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'I am Nyx',
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'tool_1234',
                    'name' => 'search',
                    'input' => [
                        'query' => 'Laravel collection methods',
                    ],
                ],
            ],
        ],
    ]);
});

it('maps tool result messages', function (): void {
    expect(MessageMap::map([
        new ToolResultMessage([
            new ToolResult(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ],
                '[search results]'
            ),
        ]),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_1234',
                    'content' => '[search results]',
                ],
            ],
        ],
    ]);
});

it('maps system messages', function (): void {
    expect(MessageMap::mapSystemMessages([
        new SystemMessage('I am Thanos.'),
        new SystemMessage('But call me Bob.'),
    ]))->toBe([
        [
            'type' => 'text',
            'text' => 'I am Thanos.',
        ],
        [
            'type' => 'text',
            'text' => 'But call me Bob.',
        ],
    ]);
});

it('sets the cache type on a UserMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
    expect(MessageMap::map([
        (new UserMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'type' => 'text',
                'text' => 'Who are you?',
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
    ]]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral,
]);

it('sets the cache type on a UserMessage image if cacheType providerOptions is set on message', function (): void {
    expect(MessageMap::map([
        (new UserMessage(
            content: 'Who are you?',
            additionalContent: [Image::fromPath('tests/Fixtures/test-image.png')]
        ))->withProviderOptions(['cacheType' => 'ephemeral']),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'type' => 'text',
                'text' => 'Who are you?',
                'cache_control' => ['type' => 'ephemeral'],
            ],
            [
                'type' => 'image',
                'cache_control' => ['type' => 'ephemeral'],
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => base64_encode(file_get_contents('tests/Fixtures/test-image.png')),
                ],
            ],
        ],
    ]]);
});

it('sets the cache type on an AssistantMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
    expect(MessageMap::map([
        (new AssistantMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            [
                'type' => 'text',
                'text' => 'Who are you?',
                'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value],
            ],
        ],
    ]]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral,
]);

it('sets the cache type on a SystemMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
    expect(MessageMap::mapSystemMessages([
        (new SystemMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
    ]))->toBe([
        [
            'type' => 'text',
            'text' => 'Who are you?',
            'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value],
        ],
    ]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral,
]);
