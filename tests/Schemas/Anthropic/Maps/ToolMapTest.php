<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic\Maps;

use Prism\Bedrock\Schemas\Anthropic\Maps\ToolMap;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toBe([[
        'name' => 'search',
        'description' => 'Searching the web',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ],
    ]]);
});

it('maps parameterless tools with empty object properties', function (): void {
    $tool = (new Tool)
        ->as('get_time')
        ->for('Get the current time')
        ->using(fn (): string => '12:00 PM');

    expect(ToolMap::map([$tool]))->toEqual([[
        'name' => 'get_time',
        'description' => 'Get the current time',
        'input_schema' => [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
        ],
    ]]);
});

it('sets the cache typeif cacheType providerOptions is set on tool', function (mixed $cacheType): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
        ->withProviderOptions(['cacheType' => $cacheType]);

    expect(ToolMap::map([$tool]))->toBe([[
        'name' => 'search',
        'description' => 'Searching the web',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => ['query'],
        ],
        'cache_control' => ['type' => 'ephemeral'],
    ]]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral->value,
]);
