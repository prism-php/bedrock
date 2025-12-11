<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse\Maps;

use Prism\Bedrock\Schemas\Converse\Maps\ToolMap;
use Prism\Prism\Tool;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toBe([
        [
            'toolSpec' => [
                'name' => 'search',
                'description' => 'Searching the web',
                'inputSchema' => [
                    'json' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'description' => 'the detailed search query',
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ],
    ]);
});

it('maps parameterless tools with empty object properties', function (): void {
    $tool = (new Tool)
        ->as('get_time')
        ->for('Get the current time')
        ->using(fn (): string => '12:00 PM');

    expect(ToolMap::map([$tool]))->toEqual([
        [
            'toolSpec' => [
                'name' => 'get_time',
                'description' => 'Get the current time',
                'inputSchema' => [
                    'json' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'required' => [],
                    ],
                ],
            ],
        ],
    ]);
});
