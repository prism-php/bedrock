<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse\Maps;

use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Document;
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
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps assistant message', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx'),
    ]))->toContain([
        'role' => 'assistant',
        'content' => [
            [
                'text' => 'I am Nyx',
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
            'text' => 'I am Thanos.',
        ],
        [
            'text' => 'But call me Bob.',
        ],
    ]);
});

it('maps an md document correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Document::fromPath('tests/Fixtures/document.md', 'Answer To Life'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'document' => [
                    'format' => 'txt',
                    'name' => 'Answer To Life',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/document.md'))],
                ],
            ],
        ],
    ]]);
});

it('maps an image correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Image::fromPath('tests/Fixtures/test-image.png'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'image' => [
                    'format' => 'png',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/test-image.png'))],
                ],
            ],
        ],
    ]]);
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
                ['text' => 'I am Nyx'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_1234',
                        'name' => 'search',
                        'input' => [
                            'query' => 'Laravel collection methods',
                        ],
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
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'toolResult' => [
                    'status' => 'success',
                    'toolUseId' => 'tool_1234',
                    'content' => [
                        ['text' => '[search results]'],
                    ],
                ],
            ],
        ],
    ]]);
});

it('maps user messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new UserMessage('Who are you?'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps assistant messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new AssistantMessage('I am Thanos'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['text' => 'I am Thanos'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps system messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::mapSystemMessages([
        (new SystemMessage('The answer to life is 42.'))->withProviderOptions(['cacheType' => 'default']),
        (new SystemMessage('Convert any numbers in your answer to their word format.')),
    ]))->toBe([
        [
            'text' => 'The answer to life is 42.',
        ],
        [
            'cachePoint' => [
                'type' => 'default',
            ],
        ],
        [
            'text' => 'Convert any numbers in your answer to their word format.',
        ],
    ]);
});
